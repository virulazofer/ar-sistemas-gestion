<?php

namespace App\Services\Imports;

use App\Enums\MovementScope;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\ImportBatch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\Catalog\ProductService;
use App\Services\Finance\MovementService;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class ImportService
{
    public const ENTITY_TYPES = ['clients', 'suppliers', 'products', 'movements', 'categories'];

    public function __construct(
        private readonly ProductService $products,
        private readonly MovementService $movements,
        private readonly AuditLogger $audit,
    ) {}

    public function parseAndPreview(string $entityType, UploadedFile $file, int $userId): ImportBatch
    {
        $entityType = strtolower(trim($entityType));
        if (! in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('Tipo de entidad no soportado.');
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('Formato no soportado. Usá CSV o Excel (.xlsx).');
        }

        $storedPath = $file->storeAs(
            'imports/'.now()->format('Y/m'),
            Str::uuid().'.'.$ext,
            'local'
        );

        $matrix = $this->readSpreadsheet(Storage::disk('local')->path($storedPath));
        $preview = $this->validateRows($entityType, $matrix);

        $batch = ImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'entity_type' => $entityType,
            'source' => 'file',
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'stored_path' => $storedPath,
            'status' => 'preview',
            'rows_total' => $preview['rows_total'],
            'rows_valid' => $preview['rows_valid'],
            'rows_invalid' => $preview['rows_invalid'],
            'rows_duplicate' => $preview['rows_duplicate'],
            'rows_imported' => 0,
            'preview_payload' => ['rows' => $preview['rows']],
            'error_summary' => $preview['error_summary'],
            'user_id' => $userId,
        ]);

        $this->audit->log('import_previewed', $batch, null, [
            'entity_type' => $entityType,
            'rows_total' => $batch->rows_total,
            'rows_valid' => $batch->rows_valid,
            'rows_invalid' => $batch->rows_invalid,
            'rows_duplicate' => $batch->rows_duplicate,
        ], 'Vista previa de importación generada');

        return $batch;
    }

    public function confirm(ImportBatch $batch): ImportBatch
    {
        if ($batch->status !== 'preview') {
            throw new InvalidArgumentException('Solo se pueden confirmar lotes en estado vista previa.');
        }

        if ((int) $batch->rows_valid < 1) {
            throw new InvalidArgumentException('No hay filas válidas para importar.');
        }

        return DB::transaction(function () use ($batch) {
            $rows = $batch->preview_payload['rows'] ?? [];
            $imported = 0;
            $errors = [];

            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'valid') {
                    continue;
                }

                try {
                    $this->importRow($batch, $row['data'] ?? []);
                    $imported++;
                } catch (Throwable $e) {
                    $errors[] = [
                        'index' => $row['index'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            if ($imported === 0 && $errors !== []) {
                throw new RuntimeException('No se importó ninguna fila: '.($errors[0]['message'] ?? 'error'));
            }

            $batch->update([
                'status' => 'confirmed',
                'rows_imported' => $imported,
                'confirmed_at' => now(),
                'error_summary' => $errors !== [] ? ['import_errors' => $errors] : $batch->error_summary,
            ]);

            $this->audit->log('import_confirmed', $batch, null, [
                'entity_type' => $batch->entity_type,
                'rows_imported' => $imported,
            ], 'Importación confirmada');

            return $batch->fresh();
        });
    }

    public function rollback(ImportBatch $batch, string $reason): ImportBatch
    {
        if ($batch->status !== 'confirmed') {
            throw new InvalidArgumentException('Solo se pueden revertir importaciones confirmadas.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Indicá un motivo de reversión.');
        }

        return DB::transaction(function () use ($batch, $reason) {
            match ($batch->entity_type) {
                'clients' => $this->rollbackClients($batch),
                'suppliers' => $this->rollbackSuppliers($batch),
                'products' => $this->rollbackProducts($batch),
                'categories' => $this->rollbackCategories($batch),
                'movements' => $this->rollbackMovements($batch, $reason),
                default => throw new InvalidArgumentException('Tipo de entidad no soportado para rollback.'),
            };

            $batch->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'rolled_back_by' => Auth::id(),
                'rollback_reason' => $reason,
            ]);

            $this->audit->log('import_rolled_back', $batch, null, [
                'entity_type' => $batch->entity_type,
                'reason' => $reason,
            ], 'Importación revertida');

            return $batch->fresh();
        });
    }

    /**
     * @return list<list<mixed>>
     */
    private function readSpreadsheet(string $absolutePath): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Eliminar filas totalmente vacías al final
        while ($matrix !== [] && $this->rowIsEmpty(end($matrix))) {
            array_pop($matrix);
        }

        return array_values($matrix);
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return array{
     *   rows: list<array{index:int,status:string,data:array,errors:list<string>}>,
     *   rows_total: int,
     *   rows_valid: int,
     *   rows_invalid: int,
     *   rows_duplicate: int,
     *   error_summary: array<string, mixed>
     * }
     */
    private function validateRows(string $entityType, array $matrix): array
    {
        if ($matrix === []) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        $headerRow = array_shift($matrix);
        $headers = $this->normalizeHeaders($headerRow);
        $required = $this->requiredHeaders($entityType);
        foreach ($required as $col) {
            if (! in_array($col, $headers, true)) {
                throw new InvalidArgumentException("Falta la columna obligatoria: {$col}");
            }
        }

        $rows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;
        $seenKeys = [];

        foreach ($matrix as $i => $raw) {
            $index = $i + 2; // 1-based + header
            if ($this->rowIsEmpty($raw)) {
                continue;
            }

            $assoc = $this->mapRow($headers, $raw);
            [$status, $data, $errors] = $this->validateEntityRow($entityType, $assoc, $seenKeys);

            if ($status === 'valid') {
                $valid++;
            } elseif ($status === 'duplicate') {
                $duplicate++;
            } else {
                $invalid++;
            }

            $rows[] = [
                'index' => $index,
                'status' => $status,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        return [
            'rows' => $rows,
            'rows_total' => count($rows),
            'rows_valid' => $valid,
            'rows_invalid' => $invalid,
            'rows_duplicate' => $duplicate,
            'error_summary' => [
                'headers' => $headers,
                'invalid_sample' => array_values(array_slice(
                    array_filter($rows, fn ($r) => $r['status'] === 'invalid'),
                    0,
                    10
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $assoc
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateEntityRow(string $entityType, array $assoc, array &$seenKeys): array
    {
        return match ($entityType) {
            'clients' => $this->validateClient($assoc, $seenKeys),
            'suppliers' => $this->validateSupplier($assoc, $seenKeys),
            'products' => $this->validateProduct($assoc, $seenKeys),
            'categories' => $this->validateCategory($assoc, $seenKeys),
            'movements' => $this->validateMovement($assoc, $seenKeys),
            default => ['invalid', $assoc, ['Tipo no soportado']],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateClient(array $data, array &$seenKeys): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'El nombre es obligatorio.';
        }

        $cuit = $this->nullIfEmpty($data['cuit'] ?? null);
        $dni = $this->nullIfEmpty($data['dni'] ?? null);
        $status = strtolower(trim((string) ($data['status'] ?? 'active'))) ?: 'active';
        if (! in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Estado inválido (active|inactive).';
        }

        $payload = [
            'name' => $name,
            'cuit' => $cuit,
            'dni' => $dni,
            'email' => $this->nullIfEmpty($data['email'] ?? null),
            'phone' => $this->nullIfEmpty($data['phone'] ?? null),
            'business_name' => $this->nullIfEmpty($data['business_name'] ?? null),
            'tax_condition' => $this->nullIfEmpty($data['tax_condition'] ?? null),
            'status' => $status,
            'external_id' => $this->nullIfEmpty($data['external_id'] ?? null),
        ];

        $dupKey = null;
        if ($cuit) {
            $dupKey = 'cuit:'.$cuit;
            if (Client::query()->where('cuit', $cuit)->exists()) {
                return ['duplicate', $payload, ['Ya existe un cliente con ese CUIT.']];
            }
        } elseif ($dni) {
            $dupKey = 'dni:'.$dni;
            if (Client::query()->where('dni', $dni)->exists()) {
                return ['duplicate', $payload, ['Ya existe un cliente con ese DNI.']];
            }
        }

        if ($dupKey && isset($seenKeys[$dupKey])) {
            return ['duplicate', $payload, ['Duplicado dentro del archivo.']];
        }
        if ($dupKey) {
            $seenKeys[$dupKey] = true;
        }

        return [$errors === [] ? 'valid' : 'invalid', $payload, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateSupplier(array $data, array &$seenKeys): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'El nombre es obligatorio.';
        }

        $cuit = $this->nullIfEmpty($data['cuit'] ?? null);
        $dni = $this->nullIfEmpty($data['dni'] ?? null);
        $status = strtolower(trim((string) ($data['status'] ?? 'active'))) ?: 'active';
        if (! in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Estado inválido (active|inactive).';
        }

        $payload = [
            'name' => $name,
            'cuit' => $cuit,
            'dni' => $dni,
            'email' => $this->nullIfEmpty($data['email'] ?? null),
            'phone' => $this->nullIfEmpty($data['phone'] ?? null),
            'status' => $status,
            'external_id' => $this->nullIfEmpty($data['external_id'] ?? null),
        ];

        $dupKey = null;
        if ($cuit) {
            $dupKey = 'cuit:'.$cuit;
            if (Supplier::query()->where('cuit', $cuit)->exists()) {
                return ['duplicate', $payload, ['Ya existe un proveedor con ese CUIT.']];
            }
        } elseif ($dni) {
            $dupKey = 'dni:'.$dni;
            if (Supplier::query()->where('dni', $dni)->exists()) {
                return ['duplicate', $payload, ['Ya existe un proveedor con ese DNI.']];
            }
        }

        if ($dupKey && isset($seenKeys[$dupKey])) {
            return ['duplicate', $payload, ['Duplicado dentro del archivo.']];
        }
        if ($dupKey) {
            $seenKeys[$dupKey] = true;
        }

        return [$errors === [] ? 'valid' : 'invalid', $payload, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateProduct(array $data, array &$seenKeys): array
    {
        $errors = [];
        $sku = trim((string) ($data['sku'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $type = strtolower(trim((string) ($data['type'] ?? ProductType::Physical->value)));
        $status = strtolower(trim((string) ($data['status'] ?? Product::STATUS_ACTIVE))) ?: Product::STATUS_ACTIVE;

        if ($sku === '') {
            $errors[] = 'El SKU es obligatorio.';
        }
        if ($name === '') {
            $errors[] = 'El nombre es obligatorio.';
        }
        if (! in_array($type, [ProductType::Physical->value, ProductType::Service->value], true)) {
            $errors[] = 'Tipo inválido (physical|service).';
        }
        if (! in_array($status, [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE], true)) {
            $errors[] = 'Estado inválido (active|inactive).';
        }

        $stockMin = $data['stock_min'] ?? 0;
        if ($stockMin === '' || $stockMin === null) {
            $stockMin = 0;
        }
        if (! is_numeric($stockMin)) {
            $errors[] = 'stock_min debe ser numérico.';
            $stockMin = 0;
        }

        $payload = [
            'sku' => $sku,
            'name' => $name,
            'type' => $type,
            'stock_min' => $stockMin,
            'status' => $status,
            'external_id' => $this->nullIfEmpty($data['external_id'] ?? null),
        ];

        if ($sku !== '') {
            if (isset($seenKeys['sku:'.$sku]) || Product::query()->where('sku', $sku)->exists()) {
                return ['duplicate', $payload, ['El SKU ya existe.']];
            }
            $seenKeys['sku:'.$sku] = true;
        }

        return [$errors === [] ? 'valid' : 'invalid', $payload, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateCategory(array $data, array &$seenKeys): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        $scope = strtolower(trim((string) ($data['scope'] ?? '')));
        $status = strtolower(trim((string) ($data['status'] ?? 'active'))) ?: 'active';

        if ($name === '') {
            $errors[] = 'El nombre es obligatorio.';
        }
        if (! in_array($scope, ['personal', 'professional', 'both'], true)) {
            $errors[] = 'Ámbito inválido (personal|professional|both).';
        }
        if (! in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true)) {
            $errors[] = 'Estado inválido.';
        }

        $isActive = in_array($status, ['active', '1', 'true'], true);

        $payload = [
            'name' => $name,
            'scope' => $scope,
            'is_active' => $isActive,
            'status' => $isActive ? 'active' : 'inactive',
        ];

        $key = 'cat:'.mb_strtolower($name).':'.$scope;
        if ($name !== '' && isset($seenKeys[$key])) {
            return ['duplicate', $payload, ['Categoría duplicada en el archivo.']];
        }
        if ($name !== '' && Category::query()->where('name', $name)->where('scope', $scope)->exists()) {
            return ['duplicate', $payload, ['Ya existe una categoría con ese nombre y ámbito.']];
        }
        if ($name !== '') {
            $seenKeys[$key] = true;
        }

        return [$errors === [] ? 'valid' : 'invalid', $payload, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenKeys
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    private function validateMovement(array $data, array &$seenKeys): array
    {
        $errors = [];
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $type = strtolower(trim((string) ($data['type'] ?? '')));
        $scope = strtolower(trim((string) ($data['scope'] ?? '')));
        $amount = $data['amount'] ?? null;
        $date = trim((string) ($data['movement_date'] ?? ''));
        $accountId = $data['financial_account_id'] ?? null;
        $accountName = trim((string) ($data['account_name'] ?? ''));

        if ($externalId === '') {
            $errors[] = 'external_id es obligatorio.';
        }
        if (! in_array($type, [MovementType::Income->value, MovementType::Expense->value], true)) {
            $errors[] = 'type debe ser income o expense.';
        }
        if (! in_array($scope, [MovementScope::Personal->value, MovementScope::Professional->value], true)) {
            $errors[] = 'scope debe ser personal o professional.';
        }
        if ($amount === null || $amount === '' || ! is_numeric($amount) || ! Money::isPositive(Money::normalize($amount))) {
            $errors[] = 'amount debe ser un importe positivo.';
        }
        if ($date === '') {
            $errors[] = 'movement_date es obligatorio.';
        }

        $resolvedAccountId = null;
        if ($accountId !== null && $accountId !== '') {
            $resolvedAccountId = (int) $accountId;
            if (! FinancialAccount::query()->whereKey($resolvedAccountId)->exists()) {
                $errors[] = 'financial_account_id no existe.';
            }
        } elseif ($accountName !== '') {
            $acc = FinancialAccount::query()->where('name', $accountName)->first();
            if (! $acc) {
                $errors[] = 'No se encontró la cuenta: '.$accountName;
            } else {
                $resolvedAccountId = $acc->id;
            }
        } else {
            $errors[] = 'Indicá financial_account_id o account_name.';
        }

        $categoryId = null;
        $categoryName = $this->nullIfEmpty($data['category_name'] ?? null);
        if ($categoryName) {
            $cat = Category::query()->where('name', $categoryName)->first();
            if (! $cat) {
                $errors[] = 'No se encontró la categoría: '.$categoryName;
            } else {
                $categoryId = $cat->id;
            }
        }

        $payload = [
            'external_id' => $externalId,
            'type' => $type,
            'scope' => $scope,
            'financial_account_id' => $resolvedAccountId,
            'account_name' => $accountName !== '' ? $accountName : null,
            'amount' => is_numeric($amount) ? Money::normalize($amount) : $amount,
            'movement_date' => $date,
            'description' => $this->nullIfEmpty($data['description'] ?? null),
            'category_id' => $categoryId,
            'category_name' => $categoryName,
        ];

        if ($externalId !== '') {
            if (isset($seenKeys['ext:'.$externalId]) || Movement::query()->where('external_id', $externalId)->exists()) {
                return ['duplicate', $payload, ['external_id ya existe.']];
            }
            $seenKeys['ext:'.$externalId] = true;
        }

        return [$errors === [] ? 'valid' : 'invalid', $payload, $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importRow(ImportBatch $batch, array $data): void
    {
        match ($batch->entity_type) {
            'clients' => $this->importClient($batch, $data),
            'suppliers' => $this->importSupplier($batch, $data),
            'products' => $this->importProduct($batch, $data),
            'categories' => $this->importCategory($batch, $data),
            'movements' => $this->importMovement($batch, $data),
            default => throw new InvalidArgumentException('Tipo no soportado.'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importClient(ImportBatch $batch, array $data): void
    {
        Client::query()->create([
            'name' => $data['name'],
            'cuit' => $data['cuit'] ?? null,
            'dni' => $data['dni'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'tax_condition' => $data['tax_condition'] ?? null,
            'status' => $data['status'] ?? Client::STATUS_ACTIVE,
            'import_batch_id' => $batch->id,
            'external_id' => $data['external_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importSupplier(ImportBatch $batch, array $data): void
    {
        Supplier::query()->create([
            'name' => $data['name'],
            'cuit' => $data['cuit'] ?? null,
            'dni' => $data['dni'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'] ?? Supplier::STATUS_ACTIVE,
            'import_batch_id' => $batch->id,
            'external_id' => $data['external_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importProduct(ImportBatch $batch, array $data): void
    {
        $product = $this->products->create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'type' => $data['type'],
            'stock_min' => $data['stock_min'] ?? 0,
            'status' => $data['status'] ?? Product::STATUS_ACTIVE,
        ]);

        $product->update([
            'import_batch_id' => $batch->id,
            'external_id' => $data['external_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importCategory(ImportBatch $batch, array $data): void
    {
        Category::query()->create([
            'name' => $data['name'],
            'scope' => $data['scope'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => 0,
            'import_batch_id' => $batch->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importMovement(ImportBatch $batch, array $data): void
    {
        $movement = $this->movements->createSimple([
            'type' => $data['type'],
            'scope' => $data['scope'],
            'financial_account_id' => (int) $data['financial_account_id'],
            'amount' => $data['amount'],
            'movement_date' => $data['movement_date'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
        ]);

        $movement->update([
            'import_batch_id' => $batch->id,
            'external_id' => $data['external_id'],
        ]);
    }

    private function rollbackClients(ImportBatch $batch): void
    {
        $clients = Client::query()->where('import_batch_id', $batch->id)->get();
        foreach ($clients as $client) {
            if ($client->ledgerEntries()->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: el cliente \"{$client->name}\" tiene movimientos en cuenta corriente."
                );
            }
            $client->delete();
        }
    }

    private function rollbackSuppliers(ImportBatch $batch): void
    {
        $suppliers = Supplier::query()->where('import_batch_id', $batch->id)->get();
        foreach ($suppliers as $supplier) {
            if ($supplier->ledgerEntries()->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: el proveedor \"{$supplier->name}\" tiene movimientos en cuenta corriente."
                );
            }
            if ($supplier->purchases()->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: el proveedor \"{$supplier->name}\" tiene compras asociadas."
                );
            }
            $supplier->delete();
        }
    }

    private function rollbackProducts(ImportBatch $batch): void
    {
        $products = Product::query()->where('import_batch_id', $batch->id)->get();
        foreach ($products as $product) {
            if ($product->inventoryMovements()->exists() || $product->lots()->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: el producto \"{$product->sku}\" tiene movimientos o lotes de stock."
                );
            }
            $product->delete();
        }
    }

    private function rollbackCategories(ImportBatch $batch): void
    {
        $categories = Category::query()->where('import_batch_id', $batch->id)->get();
        foreach ($categories as $category) {
            if ($category->subcategories()->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: la categoría \"{$category->name}\" tiene subcategorías."
                );
            }
            if (Movement::query()->where('category_id', $category->id)->exists()) {
                throw new RuntimeException(
                    "No se puede revertir: la categoría \"{$category->name}\" está usada en movimientos."
                );
            }
            $category->delete();
        }
    }

    private function rollbackMovements(ImportBatch $batch, string $reason): void
    {
        $movements = Movement::query()
            ->where('import_batch_id', $batch->id)
            ->posted()
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $this->movements->void($movement, 'Rollback importación: '.$reason);
        }
    }

    /**
     * @param  list<mixed>  $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        $headers = [];
        foreach ($headerRow as $col) {
            $key = strtolower(trim((string) $col));
            $key = str_replace([' ', '-'], '_', $key);
            $headers[] = $key;
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function requiredHeaders(string $entityType): array
    {
        return match ($entityType) {
            'clients' => ['name'],
            'suppliers' => ['name'],
            'products' => ['sku', 'name'],
            'categories' => ['name', 'scope'],
            'movements' => ['external_id', 'type', 'scope', 'amount', 'movement_date'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $raw): array
    {
        $assoc = [];
        foreach ($headers as $i => $key) {
            if ($key === '') {
                continue;
            }
            $assoc[$key] = $raw[$i] ?? null;
        }

        return $assoc;
    }

    /**
     * @param  list<mixed>|false  $row
     */
    private function rowIsEmpty(array|false $row): bool
    {
        if ($row === false) {
            return true;
        }

        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
