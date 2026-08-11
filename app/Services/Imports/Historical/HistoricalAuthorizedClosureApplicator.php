<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;

/**
 * Aplica el cierre autorizado 11E sobre filas de preview:
 * completar placeholders, excluir redundantes, crear reconstrucciones.
 * No inventa importes fuera de config/historical_closure_11e.php.
 */
class HistoricalAuthorizedClosureApplicator
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $recurringReport
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   applied: array<string, mixed>,
     *   skipped_reconstructions: list<array<string, mixed>>
     * }
     */
    public function apply(array $rows, array $recurringReport = []): array
    {
        $completions = config('historical_closure_11e.placeholder_completions', []);
        $exclusions = config('historical_closure_11e.redundant_placeholder_exclusions', []);
        $reconstructions = config('historical_closure_11e.authorized_reconstructions', []);
        $nonImportable = config('historical_closure_11e.non_importable_pendings', []);
        $origenRecon = (string) config('historical_closure_11e.reconstruction_origen', 'reconstruccion_historica_aprobada_usuario');
        $baseRow = (int) config('historical_closure_11e.reconstruction_source_row_base', 910000);

        $bySource = [];
        foreach ($rows as $idx => $row) {
            $bySource[(int) ($row['source_row'] ?? 0)] = $idx;
        }

        $applied = [
            'placeholders_completed' => [],
            'placeholders_excluded' => [],
            'reconstructions_created' => [],
            'non_importable_pendings' => [],
            'monetary_completed' => 0.0,
            'monetary_reconstructed' => 0.0,
        ];
        $skipped = [];

        // 1) Completar placeholders existentes
        foreach ($completions as $sourceRow => $spec) {
            $sourceRow = (int) $sourceRow;
            if (! isset($bySource[$sourceRow])) {
                $skipped[] = [
                    'kind' => 'placeholder_missing',
                    'source_row' => $sourceRow,
                    'reason' => 'Fila autorizada no encontrada en preview',
                ];
                continue;
            }
            $idx = $bySource[$sourceRow];
            $row = $rows[$idx];
            if (($row['review_status'] ?? '') !== ImportReviewStatus::PendingComplete->value
                && empty($row['dato_inferido'])
            ) {
                // Already completed in a prior apply — skip duplicate mutate
                if (($row['review_status'] ?? '') === ImportReviewStatus::Inferred->value
                    && in_array('placeholder_completado_autorizado', $row['flags'] ?? [], true)
                ) {
                    continue;
                }
            }

            $originalEmpty = [];
            if (empty($row['date']) && empty($row['date_original'])) {
                $originalEmpty[] = 'fecha';
            }
            if (((float) ($row['amounts']['egresos'] ?? 0)) <= 0.0001) {
                $originalEmpty[] = 'importe';
            }
            if (trim((string) ($row['excel_cuenta_category'] ?? '')) === '') {
                $originalEmpty[] = 'categoria';
            }
            if (trim((string) ($row['excel_subcuenta_account'] ?? '')) === '') {
                $originalEmpty[] = 'cuenta_medio';
            }

            $amount = (float) $spec['amount'];
            $date = (string) $spec['date'];
            $category = (string) $spec['category'];
            $account = (string) $spec['account'];

            $row['date_original'] = $row['date_original'] ?? $row['date'] ?? null;
            $row['amounts_original'] = $row['amounts_original'] ?? $row['amounts'];
            $row['excel_cuenta_category_original'] = $row['excel_cuenta_category'] ?? '';
            $row['excel_subcuenta_account_original'] = $row['excel_subcuenta_account'] ?? '';

            $row['date'] = $date;
            $row['date_inferred_month_end'] = true;
            $row['date_inference_rule'] = 'cierre_mensual_autorizado_placeholder';
            $row['excel_cuenta_category'] = $category !== ''
                ? $category
                : (string) ($row['excel_cuenta_category'] ?: 'Servicios');
            $row['excel_subcuenta_account'] = $account !== ''
                ? $account
                : (string) ($row['excel_subcuenta_account'] ?: 'MC');
            $row['amounts'] = array_merge($row['amounts'] ?? [], [
                'egresos' => $amount,
                'ingresos' => 0.0,
            ]);
            $row['dato_inferido'] = true;
            $row['importe_inferido'] = true;
            $row['original_empty_fields'] = $originalEmpty;
            $row['inference_trace'] = [
                'origen' => $spec['origen'] ?? 'completar_pendiente_autorizado_usuario',
                'regla' => $spec['regla'] ?? null,
                'amount' => $amount,
                'date' => $date,
                'category' => $row['excel_cuenta_category'],
                'account' => $row['excel_subcuenta_account'],
                'authorized_stage' => '11E',
            ];
            $row['flags'] = array_values(array_unique(array_merge(
                array_diff($row['flags'] ?? [], ['pendiente_completar']),
                [
                    'dato_inferido',
                    'importe_inferido',
                    'placeholder_completado_autorizado',
                    'fecha_inferida_cierre_mensual',
                ]
            )));
            $row['review_status'] = ImportReviewStatus::Inferred->value;
            $row['root_cause'] = 'placeholder_completado_autorizado';
            $row['import_ready'] = true;
            $row['decision_required'] = false;
            $row['needs_human_decision'] = false;
            $row['operational_reason'] = 'Placeholder completado con importe/fecha autorizados; original vacío conservado';
            $row['interpretation'] = [
                'kind' => 'simple',
                'finance_income' => 0.0,
                'finance_expense' => $amount,
                'cc_charge' => 0.0,
                'cc_payment' => 0.0,
                'economic_venta' => 0.0,
                'economic_utilidad' => 0.0,
                'merca_in' => 0.0,
                'merca_out' => 0.0,
                'excel_cc_in' => 0.0,
                'excel_cc_out' => 0.0,
                'corrections' => [],
                'flags' => $row['flags'],
                'would_generate' => [
                    'Egreso financiero '.$amount.' en '.$row['excel_subcuenta_account'],
                    'Gasto + aumento deuda tarjeta '.$amount.' ('.$row['excel_subcuenta_account'].')',
                ],
                'notes' => [
                    'Placeholder Excel completado (dato_inferido=true).',
                    'Campos originales vacíos: '.implode(', ', $originalEmpty),
                    'Origen: '.($spec['origen'] ?? 'completar_pendiente_autorizado_usuario'),
                    'Regla: '.($spec['regla'] ?? 'autorizada_usuario'),
                ],
                'account_alias' => $row['excel_subcuenta_account'],
                'is_card_liability' => true,
            ];
            $row['proposed_scope'] = $row['proposed_scope'] ?? 'personal';

            $rows[$idx] = $row;
            $applied['placeholders_completed'][] = [
                'source_row' => $sourceRow,
                'concepto' => $row['concepto'] ?? null,
                'amount' => $amount,
                'date' => $date,
            ];
            $applied['monetary_completed'] += $amount;
        }

        // 2) Excluir placeholders redundantes
        foreach ($exclusions as $sourceRow => $spec) {
            $sourceRow = (int) $sourceRow;
            if (! isset($bySource[$sourceRow])) {
                $skipped[] = [
                    'kind' => 'exclusion_missing',
                    'source_row' => $sourceRow,
                    'reason' => 'Fila a excluir no encontrada',
                ];
                continue;
            }
            $idx = $bySource[$sourceRow];
            $row = $rows[$idx];
            $reason = (string) ($spec['reason'] ?? 'placeholder_redundante_movimiento_original_existente');
            $row['review_status'] = ImportReviewStatus::Excluded->value;
            $row['root_cause'] = $reason;
            $row['flags'] = array_values(array_unique(array_merge(
                array_diff($row['flags'] ?? [], ['pendiente_completar']),
                ['excluida_placeholder_redundante', $reason]
            )));
            $row['import_ready'] = false;
            $row['decision_required'] = false;
            $row['needs_human_decision'] = false;
            $row['exclusion_reason'] = $reason;
            $row['operational_reason'] = 'Placeholder redundante: ya existe movimiento original del mes; excluido con trazabilidad';
            $row['interpretation'] = [
                'kind' => 'excluded',
                'finance_income' => 0.0,
                'finance_expense' => 0.0,
                'cc_charge' => 0.0,
                'cc_payment' => 0.0,
                'would_generate' => [],
                'notes' => [
                    'Excluida: '.$reason,
                    'No borrar trazabilidad Excel.',
                ],
            ];
            $rows[$idx] = $row;
            $applied['placeholders_excluded'][] = [
                'source_row' => $sourceRow,
                'concepto' => $row['concepto'] ?? null,
                'reason' => $reason,
            ];
        }

        // 3) Marcar pendientes no importables (478, 588)
        foreach ($nonImportable as $sourceRow => $spec) {
            $sourceRow = (int) $sourceRow;
            if (! isset($bySource[$sourceRow])) {
                continue;
            }
            $idx = $bySource[$sourceRow];
            $row = $rows[$idx];
            $row['review_status'] = ImportReviewStatus::PendingComplete->value;
            $row['import_ready'] = false;
            $row['decision_required'] = (bool) ($spec['decision_required'] ?? false);
            $row['needs_human_decision'] = false;
            $row['root_cause'] = (string) ($spec['root_cause'] ?? $row['root_cause'] ?? 'pendiente_completar');
            $row['flags'] = array_values(array_unique(array_merge($row['flags'] ?? [], [
                'pendiente_no_importable_autorizado',
                'pendiente_completar',
            ])));
            $row['operational_reason'] = (string) ($spec['note'] ?? 'Pendiente no importable; no bloquea gate');
            $notes = $row['interpretation']['notes'] ?? [];
            $notes[] = (string) ($spec['note'] ?? 'NO importar; NO inventar importe 0');
            $row['interpretation'] = array_merge($row['interpretation'] ?? [], [
                'finance_income' => 0.0,
                'finance_expense' => 0.0,
                'notes' => $notes,
            ]);
            $rows[$idx] = $row;
            $applied['non_importable_pendings'][] = [
                'source_row' => $sourceRow,
                'concepto' => $row['concepto'] ?? null,
                'root_cause' => $row['root_cause'],
            ];
        }

        // 4) Reconstrucciones históricas — evitar duplicar si ya hay equivalente del mes
        $existingIndex = $this->buildServiceMonthIndex($rows);
        $proposalIndex = [];
        foreach ($recurringReport['final_reconstruct_historical'] ?? [] as $p) {
            $proposalIndex[($p['service'] ?? '').'|'.($p['ym'] ?? '')] = $p;
        }

        $seq = 0;
        foreach ($reconstructions as $spec) {
            $seq++;
            $service = (string) $spec['service'];
            $ym = (string) $spec['ym'];
            $key = $service.'|'.$ym;
            $amount = (float) $spec['amount'];
            $account = (string) $spec['account'];
            $category = (string) $spec['category'];
            $label = (string) ($spec['label'] ?? $service);
            $date = $this->lastDayOfYm($ym);

            if (isset($existingIndex[$key]) && $existingIndex[$key] !== []) {
                $skipped[] = [
                    'kind' => 'reconstruction_duplicate_avoided',
                    'service' => $service,
                    'ym' => $ym,
                    'existing_source_rows' => $existingIndex[$key],
                    'reason' => 'Ya existe equivalente del mes; NO duplicar',
                ];
                continue;
            }

            // Preferir fecha/importe del proposal del analyzer si coincide; nunca inventar distinto al autorizado.
            $proposal = $proposalIndex[$key] ?? null;
            if ($proposal !== null && isset($proposal['importe_propuesto']) && $proposal['importe_propuesto'] !== null) {
                $proposedAmt = round((float) $proposal['importe_propuesto'], 2);
                if (abs($proposedAmt - round($amount, 2)) > 0.011) {
                    $skipped[] = [
                        'kind' => 'reconstruction_amount_mismatch',
                        'service' => $service,
                        'ym' => $ym,
                        'authorized' => $amount,
                        'analyzer' => $proposedAmt,
                        'reason' => 'Importe analyzer ≠ autorizado; DETENER sin crear',
                    ];
                    continue;
                }
            }

            $sourceRow = $baseRow + $seq;
            $row = [
                'source_file' => 'reconstruccion_historica_11e',
                'sheet' => 'Movimientos',
                'source_row' => $sourceRow,
                'row_hash' => hash('sha256', implode('|', ['recon', $service, $ym, $amount, $account])),
                'date' => $date,
                'date_raw' => null,
                'date_original' => null,
                'date_inferred_month_end' => true,
                'date_inference_rule' => 'reconstruccion_historica_ultimo_dia_mes',
                'month_context' => null,
                'concepto' => $label,
                'excel_cuenta_category' => $category,
                'excel_subcuenta_account' => $account,
                'amounts' => [
                    'ingresos' => 0.0,
                    'egresos' => $amount,
                    'cc_in' => 0.0,
                    'cc_out' => 0.0,
                    'pagos_tc' => 0.0,
                    'merca_in' => 0.0,
                    'merca_out' => 0.0,
                    'venta' => 0.0,
                    'ut_ventas' => 0.0,
                ],
                'amounts_original' => [
                    'ingresos' => 0.0,
                    'egresos' => 0.0,
                    'cc_in' => 0.0,
                    'cc_out' => 0.0,
                    'pagos_tc' => 0.0,
                    'merca_in' => 0.0,
                    'merca_out' => 0.0,
                    'venta' => 0.0,
                    'ut_ventas' => 0.0,
                ],
                'review_status' => ImportReviewStatus::Inferred->value,
                'flags' => [
                    'dato_inferido',
                    'importe_inferido',
                    'reconstruccion_historica_aprobada_usuario',
                    'fecha_inferida_cierre_mensual',
                ],
                'root_cause' => 'reconstruccion_historica_aprobada_usuario',
                'dato_inferido' => true,
                'importe_inferido' => true,
                'import_ready' => true,
                'decision_required' => false,
                'needs_human_decision' => false,
                'proposed_scope' => 'personal',
                'scope_ambiguous' => false,
                'operational_reason' => 'Reconstrucción histórica autorizada (sin fila Excel original)',
                'inference_trace' => [
                    'origen' => $origenRecon,
                    'service' => $service,
                    'ym' => $ym,
                    'amount' => $amount,
                    'account' => $account,
                    'category' => $category,
                    'authorized_stage' => '11E',
                ],
                'is_synthetic_reconstruction' => true,
                'reconstruction_service' => $service,
                'reconstruction_ym' => $ym,
                'interpretation' => [
                    'kind' => 'simple',
                    'finance_income' => 0.0,
                    'finance_expense' => $amount,
                    'cc_charge' => 0.0,
                    'cc_payment' => 0.0,
                    'economic_venta' => 0.0,
                    'economic_utilidad' => 0.0,
                    'merca_in' => 0.0,
                    'merca_out' => 0.0,
                    'excel_cc_in' => 0.0,
                    'excel_cc_out' => 0.0,
                    'corrections' => [],
                    'flags' => ['reconstruccion_historica_aprobada_usuario'],
                    'would_generate' => [
                        'Egreso financiero '.$amount.' en '.$account,
                        'Gasto + aumento deuda tarjeta '.$amount.' ('.$account.')',
                    ],
                    'notes' => [
                        'Reconstrucción histórica aprobada por usuario.',
                        'origen='.$origenRecon,
                        'Sin stock; solo movimiento financiero/tarjeta.',
                    ],
                    'account_alias' => $account,
                    'is_card_liability' => true,
                ],
            ];

            $rows[] = $row;
            $existingIndex[$key][] = $sourceRow;
            $applied['reconstructions_created'][] = [
                'source_row' => $sourceRow,
                'service' => $service,
                'ym' => $ym,
                'date' => $date,
                'amount' => $amount,
                'account' => $account,
            ];
            $applied['monetary_reconstructed'] += $amount;
        }

        $applied['monetary_completed'] = round($applied['monetary_completed'], 2);
        $applied['monetary_reconstructed'] = round($applied['monetary_reconstructed'], 2);
        $applied['skipped'] = $skipped;

        // Hard stop if amount mismatches detected
        $hardStops = array_values(array_filter(
            $skipped,
            fn ($s) => ($s['kind'] ?? '') === 'reconstruction_amount_mismatch'
        ));
        if ($hardStops !== []) {
            throw new \InvalidArgumentException(
                'Cierre 11E bloqueado: importe analyzer ≠ autorizado en reconstrucciones: '
                .json_encode($hardStops, JSON_UNESCAPED_UNICODE)
            );
        }

        return [
            'rows' => $rows,
            'applied' => $applied,
            'skipped_reconstructions' => array_values(array_filter(
                $skipped,
                fn ($s) => str_starts_with((string) ($s['kind'] ?? ''), 'reconstruction_')
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<int>>
     */
    private function buildServiceMonthIndex(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            if (in_array($row['review_status'] ?? '', [
                ImportReviewStatus::Excluded->value,
                ImportReviewStatus::PendingComplete->value,
            ], true)) {
                continue;
            }
            $eg = (float) ($row['amounts']['egresos'] ?? 0);
            if ($eg <= 0.0001 && (float) ($row['interpretation']['finance_expense'] ?? 0) <= 0.0001) {
                continue;
            }
            $service = $this->detectService(
                (string) ($row['concepto'] ?? ''),
                (string) ($row['excel_cuenta_category'] ?? '')
            );
            if ($service === null) {
                continue;
            }
            $ym = $this->rowYm($row);
            if ($ym === null) {
                continue;
            }
            $index[$service.'|'.$ym][] = (int) ($row['source_row'] ?? 0);
        }

        return $index;
    }

    private function detectService(string $concepto, string $cuenta): ?string
    {
        $norm = mb_strtolower(trim($concepto));
        $norm = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $norm);
        if (preg_match('/pedidos\s*ya[!.,]?\s*(premium|plus)\b/u', $norm)
            || preg_match('/pedidosya\s*(premium|plus)\b/u', $norm)
        ) {
            return 'pedidos_ya_premium';
        }
        if (str_contains($norm, 'youtube') && str_contains($norm, 'spotify')) {
            return 'youtube_spotify_combo';
        }
        if (preg_match('/\byoutube\b/u', $norm)) {
            return 'youtube';
        }
        if (preg_match('/\bspotify\b/u', $norm)) {
            return 'spotify';
        }
        if (preg_match('/\bmubi\b/u', $norm)) {
            return 'mubi';
        }
        if (preg_match('/falta\s+el\s+seguro(\s+del\s+auto)?/u', $norm)
            || preg_match('/mercantil\s*andina/u', $norm)
            || preg_match('/seguro\s+del\s+auto/u', $norm)
        ) {
            return 'mercantil_andina';
        }
        if (preg_match('/^(meli|mercado\s*libre|ml)\b/u', $norm)) {
            if ($cuenta !== '' && ! in_array($cuenta, ['Servicios', 'Suscripciones', 'Seguros', ''], true)) {
                return null;
            }

            return 'meli';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowYm(array $row): ?string
    {
        $date = $row['date'] ?? null;
        if (is_string($date) && preg_match('/^(\d{4}-\d{2})/', $date, $m)) {
            return $m[1];
        }
        $ym = $row['reconstruction_ym'] ?? null;

        return is_string($ym) ? $ym : null;
    }

    private function lastDayOfYm(string $ym): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ym.'-01');
        if (! $dt) {
            return $ym.'-28';
        }

        return $dt->modify('last day of this month')->format('Y-m-d');
    }
}
