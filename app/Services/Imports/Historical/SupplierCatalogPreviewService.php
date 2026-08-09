<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;
use App\Enums\ProductType;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSupplierCode;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\Catalog\ProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class SupplierCatalogPreviewService
{
    public function __construct(
        private readonly ProductService $products,
        private readonly AuditLogger $audit,
    ) {}

    public function analyzePath(string $absolutePath, string $originalFilename, int $userId, ?string $listDate = null): ImportBatch
    {
        if (! is_file($absolutePath)) {
            throw new InvalidArgumentException('Archivo de catálogo no encontrado.');
        }

        $hash = hash_file('sha256', $absolutePath);
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'xlsx');
        $storedPath = 'imports/'.now()->format('Y/m').'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($storedPath, file_get_contents($absolutePath));

        $preview = $this->buildPreview(Storage::disk('local')->path($storedPath), $listDate);

        $batch = ImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'entity_type' => 'supplier_catalog',
            'importer_kind' => 'supplier_catalog',
            'source' => 'file',
            'original_filename' => $originalFilename,
            'disk' => 'local',
            'stored_path' => $storedPath,
            'file_hash' => $hash,
            'status' => 'preview',
            'rows_total' => $preview['summary']['rows_read'],
            'rows_valid' => $preview['summary']['products_valid'],
            'rows_invalid' => $preview['summary']['errors'],
            'rows_duplicate' => $preview['summary']['duplicates'],
            'rows_green' => $preview['summary']['products_valid'] - $preview['summary']['duplicates'],
            'rows_yellow' => $preview['summary']['duplicates'],
            'rows_red' => $preview['summary']['errors'],
            'rows_imported' => 0,
            'preview_payload' => $preview,
            'classification_summary' => $preview['summary'],
            'error_summary' => ['sample_errors' => array_slice($preview['errors'], 0, 20)],
            'options' => ['list_date' => $listDate, 'confirm_creates_stock' => false],
            'user_id' => $userId,
        ]);

        $this->audit->log('supplier_catalog_previewed', $batch, null, [
            'rows' => $batch->rows_total,
            'valid' => $batch->rows_valid,
        ], 'Vista previa de catálogo proveedor');

        return $batch;
    }

    public function analyzeUpload(UploadedFile $file, int $userId, ?string $listDate = null): ImportBatch
    {
        $tmp = $file->getRealPath();
        if ($tmp === false) {
            throw new InvalidArgumentException('No se pudo leer el archivo subido.');
        }

        return $this->analyzePath($tmp, $file->getClientOriginalName(), $userId, $listDate);
    }

    /**
     * Confirm creates product masters with stock 0. Never creates purchases/lots.
     */
    public function confirm(ImportBatch $batch): ImportBatch
    {
        if ($batch->importer_kind !== 'supplier_catalog' || $batch->status !== 'preview') {
            throw new InvalidArgumentException('Solo se pueden confirmar previews de catálogo proveedor.');
        }

        return DB::transaction(function () use ($batch) {
            $rows = $batch->preview_payload['products'] ?? [];
            $supplierName = config('historical_import.catalog_supplier_name', 'INVID');
            $supplier = Supplier::query()->firstOrCreate(
                ['name' => $supplierName],
                [
                    'party_type' => 'empresa',
                    'business_name' => $supplierName,
                    'status' => 'active',
                    'tax_condition' => 'responsable_inscripto',
                ]
            );

            $imported = 0;
            foreach ($rows as $row) {
                if (($row['review_status'] ?? '') === ImportReviewStatus::Red->value) {
                    continue;
                }
                if (($row['action'] ?? '') === 'skip_duplicate') {
                    continue;
                }

                $sku = $row['sku'];
                if (Product::query()->where('sku', $sku)->exists()) {
                    continue;
                }

                $categoryId = null;
                if (! empty($row['family'])) {
                    $cat = ProductCategory::query()->firstOrCreate(
                        ['name' => $row['family']],
                        ['slug' => Str::slug($row['family']), 'sort_order' => 0, 'is_active' => true]
                    );
                    $categoryId = $cat->id;
                }

                $product = $this->products->create([
                    'sku' => $sku,
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'supplier_code' => $row['supplier_code'] ?? null,
                    'part_number' => $row['part_number'] ?? null,
                    'brand' => $row['brand'] ?? null,
                    'type' => ProductType::Physical->value,
                    'product_category_id' => $categoryId,
                    'status' => Product::STATUS_ACTIVE,
                    'stock_min' => 0,
                ]);

                $product->update([
                    'reference_cost_usd' => $row['cost_usd'] ?? null,
                    'tax_indicator' => $row['tax_indicator'] ?? null,
                    'internal_tax' => $row['internal_tax'] ?? null,
                    'list_price_date' => $row['list_date'] ?? null,
                    'default_supplier_id' => $supplier->id,
                    'supplier_comments' => $row['comments'] ?? null,
                    'import_batch_id' => $batch->id,
                    'external_id' => 'catalog:'.$supplier->id.':'.($row['supplier_code'] ?? $sku),
                ]);

                ProductSupplierCode::query()->updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'supplier_code' => (string) ($row['supplier_code'] ?? $sku),
                    ],
                    [
                        'product_id' => $product->id,
                        'part_number' => $row['part_number'] ?? null,
                        'cost_usd' => $row['cost_usd'] ?? null,
                        'tax_indicator' => $row['tax_indicator'] ?? null,
                        'internal_tax' => $row['internal_tax'] ?? null,
                        'list_date' => $row['list_date'] ?? null,
                        'is_primary' => true,
                    ]
                );

                $imported++;
            }

            $batch->update([
                'status' => 'confirmed',
                'rows_imported' => $imported,
                'confirmed_at' => now(),
            ]);

            $this->audit->log('supplier_catalog_confirmed', $batch, null, [
                'rows_imported' => $imported,
            ], 'Catálogo proveedor confirmado (stock 0)');

            return $batch->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreview(string $path, ?string $listDate): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, false, false, false);
        $spreadsheet->disconnectWorksheets();

        if ($matrix === []) {
            throw new InvalidArgumentException('Catálogo vacío.');
        }

        $header = array_map(fn ($h) => $this->normHeader((string) $h), array_shift($matrix));
        $idx = [
            'familia' => $this->findCol($header, ['familia', 'family']),
            'fabricante' => $this->findCol($header, ['fabricante', 'marca', 'brand']),
            'articulo' => $this->findCol($header, ['articulo', 'artículo', 'codigo', 'código']),
            'detalle' => $this->findCol($header, ['detalle', 'descripcion', 'descripción', 'nombre']),
            'partnumber' => $this->findCol($header, ['partnumber', 'part_number', 'pn']),
            'tax' => $this->findCol($header, ['indicador_de_impuestos_clientes', 'indicador_de_impuestos_(clientes)', 'impuestos']),
            'imp_interno' => $this->findCol($header, ['iminterno', 'impinterno', 'imp_interno']),
            'usd' => $this->findCol($header, ['usd_s_iva', 'usd_s/iva', 'usd']),
            'comments' => $this->findCol($header, ['comentarios', 'observaciones']),
        ];

        if ($idx['articulo'] === null && $idx['detalle'] === null) {
            throw new InvalidArgumentException('No se encontraron columnas ARTICULO/DETALLE.');
        }

        $products = [];
        $errors = [];
        $seenSku = [];
        $seenSupplierCode = [];
        $families = [];
        $brands = [];
        $partNumbers = 0;
        $duplicates = 0;
        $rowNum = 1;

        foreach ($matrix as $raw) {
            $rowNum++;
            $family = $this->cell($raw, $idx['familia']);
            $brand = $this->cell($raw, $idx['fabricante']);
            $article = $this->cell($raw, $idx['articulo']);
            $detail = $this->cell($raw, $idx['detalle']);
            $pn = $this->cell($raw, $idx['partnumber']);
            $tax = $this->cell($raw, $idx['tax']);
            $imp = $this->cell($raw, $idx['imp_interno']);
            $usd = $this->cell($raw, $idx['usd']);
            $comments = $this->cell($raw, $idx['comments']);

            if ($article === '' && $detail === '') {
                continue;
            }

            $rowErrors = [];
            if ($detail === '' && $article === '') {
                $rowErrors[] = 'Sin artículo ni detalle.';
            }

            $cost = null;
            if ($usd !== '' && is_numeric(str_replace(',', '.', $usd))) {
                $cost = (float) str_replace(',', '.', $usd);
            } elseif ($usd !== '') {
                $rowErrors[] = 'USD S/IVA no numérico.';
            }

            $sku = $this->makeSku($article, $pn, $detail);
            $status = ImportReviewStatus::Green->value;
            $action = 'create';

            if (isset($seenSku[$sku]) || isset($seenSupplierCode[$article])) {
                $status = ImportReviewStatus::Yellow->value;
                $action = 'skip_duplicate';
                $duplicates++;
                $rowErrors[] = 'Duplicado en archivo (SKU o código proveedor).';
            }
            if (Product::query()->where('sku', $sku)->exists()) {
                $status = ImportReviewStatus::Yellow->value;
                $action = 'skip_duplicate';
                $duplicates++;
                $rowErrors[] = 'SKU ya existe en sistema.';
            }
            if ($rowErrors !== [] && $status === ImportReviewStatus::Green->value) {
                $status = ImportReviewStatus::Red->value;
            }

            $seenSku[$sku] = true;
            if ($article !== '') {
                $seenSupplierCode[$article] = true;
            }
            if ($family !== '') {
                $families[$family] = ($families[$family] ?? 0) + 1;
            }
            if ($brand !== '') {
                $brands[$brand] = ($brands[$brand] ?? 0) + 1;
            }
            if ($pn !== '') {
                $partNumbers++;
            }

            $product = [
                'source_row' => $rowNum,
                'review_status' => $status,
                'action' => $action,
                'sku' => $sku,
                'supplier_code' => $article !== '' ? $article : null,
                'part_number' => $pn !== '' ? $pn : null,
                'name' => $detail !== '' ? $detail : ($article ?: $pn),
                'description' => $detail !== '' ? $detail : null,
                'family' => $family !== '' ? $family : null,
                'brand' => $brand !== '' ? $brand : null,
                'cost_usd' => $cost,
                'tax_indicator' => $tax !== '' ? $tax : null,
                'internal_tax' => is_numeric($imp) ? (float) $imp : null,
                'comments' => $comments !== '' ? $comments : null,
                'list_date' => $listDate,
                'stock_qty' => 0,
                'errors' => $rowErrors,
            ];

            $products[] = $product;
            if ($status === ImportReviewStatus::Red->value) {
                $errors[] = ['row' => $rowNum, 'errors' => $rowErrors];
            }
        }

        return [
            'products' => $products,
            'errors' => $errors,
            'summary' => [
                'rows_read' => count($products) + count(array_filter($matrix, fn ($r) => trim(implode('', array_map(fn ($c) => (string) $c, $r))) === '')),
                'products_valid' => count(array_filter($products, fn ($p) => $p['review_status'] !== ImportReviewStatus::Red->value)),
                'products_to_create' => count(array_filter($products, fn ($p) => ($p['action'] ?? '') === 'create')),
                'families' => count($families),
                'manufacturers' => count($brands),
                'part_numbers' => $partNumbers,
                'duplicates' => $duplicates,
                'errors' => count($errors),
                'family_breakdown' => $families,
                'brand_breakdown' => $brands,
                'note' => 'Importar catálogo NO genera compras, lotes ni stock. Stock inicial = 0.',
            ],
        ];
    }

    private function makeSku(?string $article, ?string $pn, ?string $detail): string
    {
        $base = $article ?: ($pn ?: substr(sha1((string) $detail), 0, 10));
        $base = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $base) ?: 'ITEM');

        return 'AR-'.$base;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $candidates
     */
    private function findCol(array $header, array $candidates): ?int
    {
        foreach ($header as $i => $h) {
            foreach ($candidates as $c) {
                if ($h === $this->normHeader($c) || str_contains($h, $this->normHeader($c))) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function normHeader(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * @param  list<mixed>  $row
     */
    private function cell(array $row, ?int $idx): string
    {
        if ($idx === null) {
            return '';
        }

        return trim((string) ($row[$idx] ?? ''));
    }
}
