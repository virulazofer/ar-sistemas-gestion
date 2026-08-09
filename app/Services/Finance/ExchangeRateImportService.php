<?php

namespace App\Services\Finance;

use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importador de histórico de cotización oficial (CSV/XLSX).
 * Columnas: fecha | compra | venta
 */
class ExchangeRateImportService
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @return array{
     *   rows_total: int,
     *   rows_valid: int,
     *   rows_invalid: int,
     *   rows_duplicate: int,
     *   rows: list<array{index: int, status: string, message: ?string, data: array}>,
     *   error_summary: list<string>
     * }
     */
    public function parseAndPreview(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('Formato no soportado. Usá CSV o Excel (.xlsx).');
        }

        $matrix = $this->readSpreadsheet($file->getRealPath());
        if ($matrix === []) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        $header = array_map(fn ($h) => $this->normalizeHeader((string) $h), array_shift($matrix) ?? []);
        $map = $this->mapColumns($header);

        $rows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;
        $errors = [];
        $seenKeys = [];

        foreach ($matrix as $i => $raw) {
            $index = $i + 2; // 1-based + header
            if ($this->rowEmpty($raw)) {
                continue;
            }

            $fecha = trim((string) ($raw[$map['fecha']] ?? ''));
            $compra = ($map['compra'] >= 0) ? trim((string) ($raw[$map['compra']] ?? '')) : '';
            $venta = trim((string) ($raw[$map['venta']] ?? ''));

            try {
                if ($fecha === '' || $venta === '') {
                    throw new InvalidArgumentException('Fecha y venta son obligatorias.');
                }

                $date = Carbon::parse($fecha)->startOfDay();
                if ($date->year < 1990 || $date->isFuture()) {
                    throw new InvalidArgumentException('Fecha fuera de rango válido.');
                }

                if (! is_numeric(str_replace(',', '.', $venta)) || (float) str_replace(',', '.', $venta) <= 0) {
                    throw new InvalidArgumentException('Venta inválida.');
                }
                $ventaNorm = Money::normalize(str_replace(',', '.', $venta), 6);

                $compraNorm = null;
                if ($compra !== '') {
                    if (! is_numeric(str_replace(',', '.', $compra)) || (float) str_replace(',', '.', $compra) <= 0) {
                        throw new InvalidArgumentException('Compra inválida.');
                    }
                    $compraNorm = Money::normalize(str_replace(',', '.', $compra), 6);
                }

                $key = $date->toDateString().'|'.$ventaNorm.'|'.($compraNorm ?? '');
                if (isset($seenKeys[$key])) {
                    $duplicate++;
                    $rows[] = [
                        'index' => $index,
                        'status' => 'duplicate',
                        'message' => 'Duplicado en archivo (misma fecha/valores).',
                        'data' => [],
                    ];
                    continue;
                }
                $seenKeys[$key] = true;

                $existing = $this->rates->findDuplicate(
                    provider: ExchangeRateService::PROVIDER_HISTORICAL_IMPORT,
                    rateAt: $date,
                    sell: $ventaNorm,
                    buy: $compraNorm,
                    source: ExchangeRateService::SOURCE_HISTORICAL_IMPORT,
                    matchByDay: true,
                );

                if ($existing) {
                    $duplicate++;
                    $rows[] = [
                        'index' => $index,
                        'status' => 'duplicate',
                        'message' => 'Ya existe en histórico (fecha/provider/tipo).',
                        'data' => [],
                    ];
                    continue;
                }

                $valid++;
                $rows[] = [
                    'index' => $index,
                    'status' => 'valid',
                    'message' => null,
                    'data' => [
                        'fecha' => $date->toDateString(),
                        'compra' => $compraNorm,
                        'venta' => $ventaNorm,
                    ],
                ];
            } catch (\Throwable $e) {
                $invalid++;
                $msg = $e->getMessage();
                $errors[] = "Fila {$index}: {$msg}";
                $rows[] = [
                    'index' => $index,
                    'status' => 'invalid',
                    'message' => $msg,
                    'data' => [],
                ];
            }
        }

        return [
            'rows_total' => count($rows),
            'rows_valid' => $valid,
            'rows_invalid' => $invalid,
            'rows_duplicate' => $duplicate,
            'rows' => $rows,
            'error_summary' => $errors,
            'token' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array{rows: list<array>}  $preview
     * @return array{imported: int, skipped: int}
     */
    public function confirm(array $preview): array
    {
        $imported = 0;
        $skipped = 0;
        $userId = Auth::id();

        foreach ($preview['rows'] ?? [] as $row) {
            if (($row['status'] ?? '') !== 'valid') {
                $skipped++;
                continue;
            }
            $data = $row['data'] ?? [];
            $before = $this->rates->findDuplicate(
                provider: ExchangeRateService::PROVIDER_HISTORICAL_IMPORT,
                rateAt: Carbon::parse($data['fecha'])->startOfDay(),
                sell: (string) $data['venta'],
                buy: $data['compra'] ?? null,
                source: ExchangeRateService::SOURCE_HISTORICAL_IMPORT,
                matchByDay: true,
            );
            $stored = $this->rates->storeHistoricalImport(
                date: $data['fecha'],
                sell: (string) $data['venta'],
                buy: $data['compra'] ?? null,
                notes: 'Importación histórica CSV/XLSX',
                createdBy: $userId,
            );
            if ($before && $before->id === $stored->id) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return list<list<mixed>>
     */
    private function readSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $rows[] = array_values($row);
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $h = Str::lower(trim($header));
        $h = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $h);

        return preg_replace('/\s+/', '_', $h) ?? $h;
    }

    /**
     * @param  list<string>  $header
     * @return array{fecha: int, compra: int, venta: int}
     */
    private function mapColumns(array $header): array
    {
        $aliases = [
            'fecha' => ['fecha', 'date', 'dia', 'day'],
            'compra' => ['compra', 'buy', 'bid'],
            'venta' => ['venta', 'sell', 'ask', 'rate', 'valor'],
        ];

        $map = [];
        foreach ($aliases as $key => $names) {
            foreach ($header as $idx => $col) {
                if (in_array($col, $names, true)) {
                    $map[$key] = $idx;
                    break;
                }
            }
        }

        if (! isset($map['fecha'], $map['venta'])) {
            throw new InvalidArgumentException('El archivo debe incluir columnas fecha y venta (compra opcional).');
        }
        $map['compra'] = $map['compra'] ?? -1;

        return $map;
    }

    /**
     * @param  list<mixed>  $raw
     */
    private function rowEmpty(array $raw): bool
    {
        foreach ($raw as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
