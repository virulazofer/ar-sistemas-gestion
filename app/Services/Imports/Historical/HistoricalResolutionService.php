<?php

namespace App\Services\Imports\Historical;

use App\Models\ImportBatch;
use App\Models\ImportPreviewDecision;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Decisiones de preview histórico (sin confirmar importación real).
 */
class HistoricalResolutionService
{
    public function __construct(
        private readonly HistoricalMovementsPreviewService $movements,
        private readonly HistoricalMappingRuleService $rules,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAllRows(ImportBatch $batch): array
    {
        $path = $batch->preview_payload['rows_all_path'] ?? null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new InvalidArgumentException('No hay dump de filas del preview. Reprocesá el lote.');
        }
        $data = json_decode(Storage::disk('local')->get($path), true) ?: [];

        return $data['rows'] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    public function reviewQueues(array $rows, ImportBatch $batch): array
    {
        $decisions = ImportPreviewDecision::query()
            ->where('import_batch_id', $batch->id)
            ->get()
            ->groupBy(fn ($d) => $d->decision_type.':'.$d->source_row);

        $dates = [];
        $complex = [];
        $cards = [];
        $cc = [];
        $merca = [];
        $scope = [];

        foreach ($rows as $row) {
            $cause = $row['root_cause'] ?? '';
            $flags = $row['flags'] ?? [];
            $sourceRow = (int) ($row['source_row'] ?? 0);

            if (in_array('fecha_sospechosa', $flags, true) || $cause === 'fecha_invalida_sospechosa' || $cause === 'fecha_dudosa') {
                $dates[] = $this->enrichDateRow($row, $decisions->get('date:'.$sourceRow)?->first());
            }
            if ($cause === 'operacion_venta_compleja' || in_array('operacion_compleja', $flags, true)) {
                if (($row['amounts']['venta'] ?? 0) > 0 || $cause === 'operacion_venta_compleja') {
                    $complex[] = $this->enrichComplexRow($row, $decisions->get('complex_sale:'.$sourceRow)?->first());
                }
            }
            if ($cause === 'pago_tarjeta' || in_array('pago_tarjeta_posible', $flags, true)) {
                $cards[] = $this->enrichCardRow($row, $decisions->get('card:'.$sourceRow)?->first());
            }
            if ($cause === 'cc_simple_revision') {
                $cc[] = $row;
            }
            if ($cause === 'merca_analisis' || in_array('merca_analisis_only', $flags, true)) {
                if (! in_array('operacion_compleja', $flags, true)) {
                    $merca[] = $row;
                }
            }
            if ($cause === 'ambito_dudoso' || ! empty($row['scope_ambiguous'])) {
                $scope[] = $this->enrichScopeRow($row, $decisions->get('scope:'.$sourceRow)?->first());
            }
        }

        return compact('dates', 'complex', 'cards', 'cc', 'merca', 'scope');
    }

    public function decideDate(ImportBatch $batch, int $sourceRow, string $action, ?string $correctedDate = null): ImportPreviewDecision
    {
        if (! in_array($action, ['accept', 'correct', 'exclude'], true)) {
            throw new InvalidArgumentException('Acción de fecha inválida.');
        }
        if ($action === 'correct') {
            if (! $correctedDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $correctedDate)) {
                throw new InvalidArgumentException('Fecha corregida debe ser AAAA-MM-DD.');
            }
            [$y, $m, $d] = array_map('intval', explode('-', $correctedDate));
            if (! checkdate($m, $d, $y)) {
                throw new InvalidArgumentException('Fecha corregida imposible.');
            }
        }

        $decision = ImportPreviewDecision::query()->updateOrCreate(
            [
                'import_batch_id' => $batch->id,
                'decision_type' => 'date',
                'source_row' => $sourceRow,
                'match_key' => '',
            ],
            [
                'payload' => [
                    'action' => $action,
                    'corrected_date' => $action === 'correct' ? $correctedDate : null,
                ],
                'decided_by' => Auth::id(),
            ]
        );

        $this->audit->log('historical_date_decision', $batch, null, $decision->toArray(), "Fecha fila {$sourceRow}: {$action}");

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $components
     */
    public function decideComplexSale(ImportBatch $batch, int $sourceRow, array $components): ImportPreviewDecision
    {
        $payload = [
            'approved' => true,
            'venta' => (float) ($components['venta'] ?? 0),
            'cobro' => (float) ($components['cobro'] ?? 0),
            'cc_charge' => (float) ($components['cc_charge'] ?? 0),
            'cc_payment' => (float) ($components['cc_payment'] ?? 0),
            'merca_out' => (float) ($components['merca_out'] ?? 0),
            'merca_in' => (float) ($components['merca_in'] ?? 0),
            'utilidad' => (float) ($components['utilidad'] ?? 0),
            'finance_income' => (float) ($components['finance_income'] ?? $components['cobro'] ?? 0),
            'finance_expense' => (float) ($components['finance_expense'] ?? 0),
            'client' => $components['client'] ?? null,
            'notes' => $components['notes'] ?? null,
        ];

        $decision = ImportPreviewDecision::query()->updateOrCreate(
            [
                'import_batch_id' => $batch->id,
                'decision_type' => 'complex_sale',
                'source_row' => $sourceRow,
                'match_key' => '',
            ],
            [
                'payload' => $payload,
                'decided_by' => Auth::id(),
            ]
        );

        $this->audit->log('historical_complex_sale_decision', $batch, null, $decision->toArray(), "Venta compleja fila {$sourceRow}");

        return $decision;
    }

    public function decideCard(ImportBatch $batch, int $sourceRow, string $kind): ImportPreviewDecision
    {
        if (! in_array($kind, ['purchase', 'statement_payment'], true)) {
            throw new InvalidArgumentException('Tipo de tarjeta inválido.');
        }

        $decision = ImportPreviewDecision::query()->updateOrCreate(
            [
                'import_batch_id' => $batch->id,
                'decision_type' => 'card',
                'source_row' => $sourceRow,
                'match_key' => '',
            ],
            [
                'payload' => ['kind' => $kind],
                'decided_by' => Auth::id(),
            ]
        );

        $this->audit->log('historical_card_decision', $batch, null, $decision->toArray(), "Tarjeta fila {$sourceRow}: {$kind}");

        return $decision;
    }

    /**
     * @param  list<int>  $sourceRows
     */
    public function decideScopeBulk(ImportBatch $batch, array $sourceRows, string $scope, bool $saveReusableRule = false, ?string $ruleMatch = null): int
    {
        if (! in_array($scope, ['personal', 'professional', 'pending'], true)) {
            throw new InvalidArgumentException('Ámbito inválido.');
        }

        $count = 0;
        foreach ($sourceRows as $sourceRow) {
            ImportPreviewDecision::query()->updateOrCreate(
                [
                    'import_batch_id' => $batch->id,
                    'decision_type' => 'scope',
                    'source_row' => (int) $sourceRow,
                    'match_key' => '',
                ],
                [
                    'payload' => ['scope' => $scope],
                    'decided_by' => Auth::id(),
                ]
            );
            $count++;
        }

        if ($saveReusableRule && $ruleMatch && $scope !== 'pending') {
            $this->rules->approveScopeConceptRule($ruleMatch, $scope);
        }

        $this->audit->log('historical_scope_bulk', $batch, null, [
            'rows' => count($sourceRows),
            'scope' => $scope,
            'reusable' => $saveReusableRule,
            'match' => $ruleMatch,
        ], 'Clasificación de ámbito en preview');

        return $count;
    }

    public function reprocessWithEvolution(ImportBatch $batch): ImportBatch
    {
        $before = [
            'green' => (int) $batch->rows_green,
            'yellow' => (int) $batch->rows_yellow,
            'red' => (int) $batch->rows_red,
            'differences' => $batch->reconciliation_payload['differences'] ?? [],
        ];

        $updated = $this->movements->reprocess($batch->fresh());

        $options = $updated->options ?? [];
        $options['evolution'] = [
            'before' => $before,
            'after' => [
                'green' => (int) $updated->rows_green,
                'yellow' => (int) $updated->rows_yellow,
                'red' => (int) $updated->rows_red,
                'differences' => $updated->reconciliation_payload['differences'] ?? [],
            ],
            'at' => now()->toDateTimeString(),
        ];
        $updated->update(['options' => $options]);

        return $updated->fresh();
    }

    /**
     * @return array<int, ImportPreviewDecision>
     */
    public function decisionsByRow(ImportBatch $batch, string $type): array
    {
        return ImportPreviewDecision::query()
            ->where('import_batch_id', $batch->id)
            ->where('decision_type', $type)
            ->where('source_row', '>', 0)
            ->get()
            ->keyBy('source_row')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichDateRow(array $row, ?ImportPreviewDecision $decision): array
    {
        $iso = $row['date'] ?? null;
        $suggested = null;
        $reason = 'Fecha fuera de período histórico o inválida';
        if (is_string($iso) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            $year = (int) $m[1];
            if ($year === 2025) {
                $suggested = '2026-'.$m[2].'-'.$m[3];
                $reason = 'Año 2025 dentro de planilla 2026 (posible tipeo; no auto-corregir)';
            } elseif ($iso < (string) config('historical_import.period_from') || $iso > (string) config('historical_import.period_to')) {
                $reason = 'Fuera del período '.$row['date'].' vs ventana histórica';
            }
        } else {
            $reason = 'Fecha no parseable / vacía';
        }

        $amount = (float) ($row['amounts']['ingresos'] ?? 0)
            + (float) ($row['amounts']['egresos'] ?? 0)
            + (float) ($row['amounts']['cc_in'] ?? 0)
            + (float) ($row['amounts']['cc_out'] ?? 0)
            + (float) ($row['amounts']['pagos_tc'] ?? 0)
            + (float) ($row['amounts']['venta'] ?? 0);

        return array_merge($row, [
            'suggested_date' => $suggested,
            'suspicion_reason' => $reason,
            'display_amount' => $amount,
            'decision' => $decision?->payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichComplexRow(array $row, ?ImportPreviewDecision $decision): array
    {
        $a = $row['amounts'] ?? [];
        $proposed = [
            'venta' => (float) ($a['venta'] ?? 0),
            'cobro' => (float) ($a['ingresos'] ?? 0),
            'cc_charge' => (float) ($a['cc_in'] ?? 0),
            'cc_payment' => (float) ($a['cc_out'] ?? 0),
            'merca_out' => (float) ($a['merca_out'] ?? 0),
            'merca_in' => (float) ($a['merca_in'] ?? 0),
            'utilidad' => (float) ($a['ut_ventas'] ?? 0),
            'finance_income' => (float) ($a['ingresos'] ?? 0),
            'finance_expense' => (float) ($a['egresos'] ?? 0),
            'notes' => 'Propuesta desde columnas Excel — revisar antes de aprobar.',
        ];

        return array_merge($row, [
            'proposed_components' => $proposed,
            'decision' => $decision?->payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichCardRow(array $row, ?ImportPreviewDecision $decision): array
    {
        $a = $row['amounts'] ?? [];
        $cuenta = $row['excel_cuenta_category'] ?? '';
        $suggested = 'purchase';
        if (($a['pagos_tc'] ?? 0) > 0 || in_array($cuenta, ['VISA', 'MC', 'MCMP'], true) && ($a['egresos'] ?? 0) <= 0 && ($a['ingresos'] ?? 0) <= 0) {
            $suggested = 'statement_payment';
        }
        if (($a['egresos'] ?? 0) > 0 && in_array($row['excel_subcuenta_account'] ?? '', ['VISA', 'MC', 'MCMP'], true)) {
            $suggested = 'purchase';
        }

        return array_merge($row, [
            'suggested_card_kind' => $suggested,
            'decision' => $decision?->payload,
            'before_after' => [
                'purchase' => [
                    'would_generate' => ['Gasto/compra', 'Aumento pasivo tarjeta'],
                    'not_generate' => ['Pago de resumen'],
                ],
                'statement_payment' => [
                    'would_generate' => ['Disminuye banco/efectivo', 'Disminuye pasivo tarjeta'],
                    'not_generate' => ['Segundo gasto'],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichScopeRow(array $row, ?ImportPreviewDecision $decision): array
    {
        $amount = (float) ($row['amounts']['egresos'] ?? 0) + (float) ($row['amounts']['ingresos'] ?? 0);

        return array_merge($row, [
            'display_amount' => $amount,
            'current_scope' => $decision?->payload['scope'] ?? ($row['proposed_scope'] ?? null),
            'suggested_scope' => $row['proposed_scope'] ?? 'personal',
            'decision' => $decision?->payload,
        ]);
    }
}
