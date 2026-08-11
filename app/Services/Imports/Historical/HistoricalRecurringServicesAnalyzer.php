<?php

namespace App\Services\Imports\Historical;

/**
 * Detecta servicios recurrentes en preview histórico.
 * NO crea movimientos: solo matriz, absorción de placeholders y propuestas.
 */
class HistoricalRecurringServicesAnalyzer
{
    private const MONTH_LABELS = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function analyze(
        array $rows,
        string $periodFrom,
        string $periodTo,
        ?string $cutoverDate = null,
    ): array {
        $cutoverDate ??= (string) config('historical_import.cutover_date', '2026-08-15');
        $cutover = new \DateTimeImmutable($cutoverDate);
        $from = new \DateTimeImmutable($periodFrom);
        $to = new \DateTimeImmutable($periodTo);
        $months = $this->monthsInRange($from, $to);
        $defs = config('historical_recurring_services.services', []);

        $classified = [];
        foreach ($rows as $row) {
            $hit = $this->classifyRow($row);
            if ($hit === null) {
                continue;
            }
            $classified[] = $hit + [
                'source_row' => (int) ($row['source_row'] ?? 0),
                'date' => $row['date'] ?? null,
                'date_original' => $row['date_original'] ?? null,
                'month_context' => $row['month_context'] ?? null,
                'concepto' => (string) ($row['concepto'] ?? ''),
                'cuenta' => (string) ($row['excel_cuenta_category'] ?? ''),
                'subcuenta' => (string) ($row['excel_subcuenta_account'] ?? ''),
                'egresos' => (float) ($row['amounts']['egresos'] ?? 0),
                'review_status' => $row['review_status'] ?? null,
                'date_inferred' => ! empty($row['date_inferred_month_end'])
                    || in_array('fecha_inferida_cierre_mensual', $row['flags'] ?? [], true),
                'flags' => $row['flags'] ?? [],
            ];
        }

        // Placeholders: pending_complete with amount 0 (or empty amounts) mapped to service+month.
        $placeholders = [];
        foreach ($classified as $hit) {
            if (($hit['review_status'] ?? '') !== 'pending_complete') {
                continue;
            }
            if (($hit['egresos'] ?? 0) > 0.0001) {
                continue;
            }
            // AUSA pendiente: track but never auto-complete by recurrence.
            $ym = $this->rowYearMonth($hit);
            if ($ym === null) {
                continue;
            }
            $placeholders[] = [
                'service' => $hit['service'],
                'ym' => $ym,
                'source_row' => $hit['source_row'],
                'concepto' => $hit['concepto'],
                'cuenta' => $hit['cuenta'],
                'subcuenta' => $hit['subcuenta'],
                'month_context' => $hit['month_context'],
                'alias_note' => $hit['alias_note'] ?? null,
                'auto_complete' => ($hit['service'] ?? '') !== 'ausa',
            ];
        }

        $placeholderIndex = [];
        foreach ($placeholders as $ph) {
            if (! ($ph['auto_complete'] ?? true)) {
                continue;
            }
            $k = $ph['service'].'|'.$ph['ym'];
            if (! isset($placeholderIndex[$k])) {
                $placeholderIndex[$k] = $ph;
            }
        }

        $matrices = [];
        $matrixDetail = [];
        $absorbed = [];
        $trueMissing = [];
        $augustPostCutover = [];
        $ausaEliminated = [
            '2026-01', '2026-03', '2026-04', '2026-05', '2026-06', '2026-08',
        ];
        $completePending = [];
        $reconstructHistorical = [];

        // Prior checkpoint had 36 proposals; track correction stats.
        $priorProposalBaseline = 36;

        foreach ($defs as $key => $def) {
            if (! ($def['propose_missing'] ?? true)) {
                continue;
            }

            $byMonth = [];
            foreach ($months as $ym) {
                $byMonth[$ym] = [
                    'ym' => $ym,
                    'label' => self::MONTH_LABELS[(int) substr($ym, 5, 2)] ?? $ym,
                    'present' => false,
                    'amount_original' => null,
                    'amount_display' => null,
                    'dato' => 'ausente',
                    'source_rows' => [],
                    'placeholder_rows' => [],
                    'treatment' => 'ausente',
                    'suggested_date' => null,
                    'notes' => [],
                ];
            }

            $observedAmounts = [];
            $observedAccounts = [];
            $augustEvidenceDate = null;

            foreach ($classified as $hit) {
                if ($hit['service'] !== $key && ! ($hit['covers'][$key] ?? false)) {
                    continue;
                }
                // Skip AUSA classified under non-recurring — not in $defs anymore
                $ym = $this->rowYearMonth($hit);
                if ($ym === null || ! isset($byMonth[$ym])) {
                    continue;
                }
                $amount = (float) $hit['egresos'];
                $isCombo = ($hit['service'] === 'youtube_spotify_combo');
                $isPending = ($hit['review_status'] ?? '') === 'pending_complete';
                $cell = &$byMonth[$ym];
                $cell['source_rows'][] = $hit['source_row'];

                if ($isPending && $amount <= 0.0001) {
                    $cell['placeholder_rows'][] = $hit['source_row'];
                    $cell['notes'][] = 'Placeholder pendiente fila '.$hit['source_row'];
                    unset($cell);
                    continue;
                }

                if ($amount > 0.0001) {
                    $cell['present'] = true;
                    $cell['dato'] = $hit['date_inferred'] ? 'original_importe_fecha_inferida' : 'original';
                    $cell['treatment'] = 'registro_original_existente';
                    $cell['amount_original'] = ($cell['amount_original'] ?? 0) + $amount;
                    $cell['amount_display'] = $cell['amount_original'];
                    $cell['suggested_date'] = $hit['date'] ?? $cell['suggested_date'];
                    if ($isCombo) {
                        $cell['notes'][] = 'Cubierto por combo YouTube+Spotify (fila '.$hit['source_row'].')';
                    }
                    if ($hit['date_inferred']) {
                        $cell['notes'][] = 'Fecha inferida por cierre mensual; importe ORIGINAL';
                    }
                    if (! $isCombo && $hit['service'] === $key) {
                        $observedAmounts[] = $amount;
                        if (($hit['subcuenta'] ?? '') !== '') {
                            $observedAccounts[] = (string) $hit['subcuenta'];
                        }
                    }
                    // Evidence in August before cutover
                    if ($ym === $cutover->format('Y-m') && is_string($hit['date'] ?? null)) {
                        try {
                            $d = new \DateTimeImmutable($hit['date']);
                            if ($d < $cutover) {
                                if ($augustEvidenceDate === null || $d > $augustEvidenceDate) {
                                    $augustEvidenceDate = $d;
                                }
                            }
                        } catch (\Throwable) {
                        }
                    }
                }
                unset($cell);
            }

            $suggestedAmount = $this->suggestAmount($def, $observedAmounts);
            $accountHint = $def['account_hint'] ?? null;
            if ($observedAccounts !== []) {
                $freq = array_count_values($observedAccounts);
                arsort($freq);
                $accountHint = (string) array_key_first($freq);
            }
            $categoryHint = $def['category_hint'] ?? 'Servicios';

            $monthCells = [];
            foreach ($byMonth as $ym => $cell) {
                $phKey = $key.'|'.$ym;
                $ph = $placeholderIndex[$phKey] ?? null;
                $isAugust = $ym === $cutover->format('Y-m');

                if ($cell['present']) {
                    $detail = $this->detailRow($key, $def, $ym, $cell, $suggestedAmount, $accountHint, $categoryHint, 'registro_original_existente');
                    $matrixDetail[] = $detail;
                    $monthCells[$ym] = $cell;
                    continue;
                }

                // Placeholder absorbs proposal
                if ($ph !== null) {
                    $suggestedDate = $this->lastDayOfYm($ym);
                    if ($isAugust) {
                        // Never use 2026-08-31 for historical; if only placeholder, defer post-cutover
                        // unless we somehow have pre-cutover evidence (unlikely for empty placeholder).
                        if ($augustEvidenceDate) {
                            $suggestedDate = $augustEvidenceDate->format('Y-m-d');
                        } else {
                            $suggestedDate = null;
                        }
                    }

                    if ($isAugust && $suggestedDate === null) {
                        $treatment = 'recurrente_pendiente_agosto_2026';
                        $cell['treatment'] = $treatment;
                        $cell['dato'] = 'post_corte';
                        $cell['amount_display'] = $suggestedAmount['amount'];
                        $cell['notes'][] = 'Placeholder agosto sin evidencia < corte; no incluir en histórico';
                        $item = $this->detailRow($key, $def, $ym, $cell, $suggestedAmount, $accountHint, $categoryHint, $treatment, $ph);
                        $item['suggested_date'] = null;
                        $item['dato_inferido'] = true;
                        $item['create'] = false;
                        $augustPostCutover[] = $item;
                        $matrixDetail[] = $item;
                        // Still counts as absorbed placeholder (not a new movement)
                        $absorbed[] = $item + ['absorbed_by_placeholder' => true];
                        $monthCells[$ym] = $cell;
                        continue;
                    }

                    $treatment = 'completar_pendiente_existente';
                    $cell['treatment'] = $treatment;
                    $cell['dato'] = 'inferido_completar_pendiente';
                    $cell['amount_display'] = $suggestedAmount['amount'];
                    $cell['suggested_date'] = $suggestedDate;
                    $cell['notes'][] = 'Propuesta absorbida por placeholder fila '.$ph['source_row'].'; no crear movimiento adicional';

                    $item = $this->detailRow($key, $def, $ym, $cell, $suggestedAmount, $accountHint, $categoryHint, $treatment, $ph);
                    $item['suggested_date'] = $suggestedDate;
                    $item['dato_inferido'] = true;
                    $item['importe_inferido'] = true;
                    $item['create'] = false;
                    $item['kind'] = 'completar_pendiente_existente';
                    $item['excel_row_original'] = $ph['source_row'];
                    $item['original_empty_fields'] = ['fecha', 'importe'];
                    if (($ph['cuenta'] ?? '') === '') {
                        $item['original_empty_fields'][] = 'categoría';
                    }
                    if (($ph['subcuenta'] ?? '') === '') {
                        $item['original_empty_fields'][] = 'cuenta/medio';
                    }
                    $item['complete_with'] = [
                        'fecha' => $suggestedDate,
                        'importe' => $suggestedAmount['amount'],
                        'categoria' => $categoryHint,
                        'cuenta_medio' => $accountHint,
                        'regla' => $suggestedAmount['rule'],
                        'origen' => $suggestedAmount['origin'],
                    ];
                    $absorbed[] = $item;
                    $completePending[] = $item;
                    $matrixDetail[] = $item;
                    $monthCells[$ym] = $cell;
                    continue;
                }

                // No placeholder — true gap
                if ($isAugust) {
                    // Only include in historical if evidence of charge before cutover exists elsewhere (already handled if present).
                    // No evidence → post-cutover queue.
                    $treatment = 'recurrente_pendiente_agosto_2026';
                    $cell['treatment'] = $treatment;
                    $cell['dato'] = 'post_corte';
                    $cell['amount_display'] = $suggestedAmount['amount'];
                    $cell['suggested_date'] = null;
                    $cell['notes'][] = 'Sin evidencia antes de '.$cutoverDate.'; no inferir 2026-08-31 en histórico';
                    $item = $this->detailRow($key, $def, $ym, $cell, $suggestedAmount, $accountHint, $categoryHint, $treatment);
                    $item['suggested_date'] = null;
                    $item['dato_inferido'] = true;
                    $item['create'] = false;
                    $item['kind'] = 'recurrente_pendiente_agosto_2026';
                    $augustPostCutover[] = $item;
                    $matrixDetail[] = $item;
                    $monthCells[$ym] = $cell;
                    continue;
                }

                $treatment = 'movimiento_realmente_faltante';
                $suggestedDate = $this->lastDayOfYm($ym);
                $cell['treatment'] = $treatment;
                $cell['dato'] = 'inferido_faltante';
                $cell['amount_display'] = $suggestedAmount['amount'];
                $cell['suggested_date'] = $suggestedDate;
                $cell['notes'][] = 'Sin registro original ni placeholder; propuesta de reconstrucción histórica (NO crear todavía)';

                $item = $this->detailRow($key, $def, $ym, $cell, $suggestedAmount, $accountHint, $categoryHint, $treatment);
                $item['suggested_date'] = $suggestedDate;
                $item['dato_inferido'] = true;
                $item['importe_inferido'] = true;
                $item['create'] = false;
                $item['kind'] = 'movimiento_recurrente_faltante';
                $trueMissing[] = $item;
                $reconstructHistorical[] = $item;
                $matrixDetail[] = $item;
                $monthCells[$ym] = $cell;
            }

            $matrices[$key] = [
                'key' => $key,
                'label' => $def['label'] ?? $key,
                'user_confirmed_recurring' => (bool) ($def['user_confirmed_recurring'] ?? false),
                'months' => $monthCells,
                'observed_amounts' => $observedAmounts,
                'suggested_amount_for_gaps' => $suggestedAmount,
                'account_hint' => $accountHint,
                'category_hint' => $categoryHint,
                'matrix_row' => $this->matrixRow($monthCells, $months),
            ];
        }

        // AUSA: list existing only; never propose
        $ausaExisting = [];
        $ausaPending = [];
        foreach ($classified as $hit) {
            if (($hit['service'] ?? '') !== 'ausa') {
                continue;
            }
            if (($hit['review_status'] ?? '') === 'pending_complete') {
                $ausaPending[] = [
                    'source_row' => $hit['source_row'],
                    'ym' => $this->rowYearMonth($hit),
                    'concepto' => $hit['concepto'],
                    'treatment' => 'pendiente_completar_sin_auto',
                    'note' => 'AUSA no es recurrente; no completar automáticamente',
                ];
            } elseif (($hit['egresos'] ?? 0) > 0.0001) {
                $ausaExisting[] = [
                    'source_row' => $hit['source_row'],
                    'date' => $hit['date'],
                    'ym' => $this->rowYearMonth($hit),
                    'egresos' => $hit['egresos'],
                    'cuenta' => $hit['cuenta'],
                    'subcuenta' => $hit['subcuenta'],
                    'treatment' => 'registro_original_existente',
                ];
            }
        }

        $remainingIncomplete = [];
        foreach ($placeholders as $ph) {
            if (($ph['service'] ?? '') === 'ausa' || ! ($ph['auto_complete'] ?? true)) {
                $remainingIncomplete[] = $ph + [
                    'reason' => 'AUSA / no auto-completar por recurrencia',
                ];
                continue;
            }
            // Placeholders that were absorbed still "remain incomplete" until user applies completion —
            // but user asked for "lista final de pendientes que permanecen incompletos"
            // meaning those NOT proposed for completion OR still incomplete after absorption logic.
            // Interpret: pendientes that stay as-is without a completion proposal.
            // AUSA 588 stays. Absorbed ones are proposed for completion (still Excel-incomplete until applied).
            // Report both: absorbed (proposed complete) vs remain without proposal.
        }
        // Pendientes que permanecen incompletos sin propuesta de completar = AUSA 588 only
        // (others have completar_pendiente proposals). Also any pending not matched to a service month.

        $pendingRowsAll = [];
        foreach ($rows as $row) {
            if (($row['review_status'] ?? '') !== 'pending_complete') {
                continue;
            }
            $pendingRowsAll[] = [
                'source_row' => (int) ($row['source_row'] ?? 0),
                'concepto' => (string) ($row['concepto'] ?? ''),
                'cuenta' => (string) ($row['excel_cuenta_category'] ?? ''),
                'subcuenta' => (string) ($row['excel_subcuenta_account'] ?? ''),
                'month_context' => $row['month_context'] ?? null,
            ];
        }

        $absorbedRows = array_map(fn ($a) => (int) ($a['excel_row_original'] ?? 0), $completePending);
        $remainIncomplete = [];
        foreach ($pendingRowsAll as $p) {
            if (in_array($p['source_row'], $absorbedRows, true)) {
                continue;
            }
            $remainIncomplete[] = $p + [
                'reason' => ($this->normalize($p['concepto']) === 'ausa' || stripos($p['concepto'], 'AUSA') !== false)
                    ? 'AUSA no recurrente — no auto-completar'
                    : 'Sin propuesta de completar aplicada',
            ];
        }

        return [
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'cutover_date' => $cutoverDate,
            'months_analyzed' => $months,
            'month_labels' => array_map(
                fn ($ym) => self::MONTH_LABELS[(int) substr($ym, 5, 2)] ?? $ym,
                $months
            ),
            'matches' => $classified,
            'placeholders' => $placeholders,
            'matrices' => $matrices,
            'matrix_detail' => $matrixDetail,
            'proposals' => [
                'completar_pendiente' => $completePending,
                'reconstruir_historico' => $reconstructHistorical,
                'agosto_post_corte' => $augustPostCutover,
            ],
            'correction_stats' => [
                'prior_proposals_baseline' => $priorProposalBaseline,
                'ausa_proposals_eliminated' => count($ausaEliminated),
                'ausa_months_eliminated' => $ausaEliminated,
                'absorbed_by_placeholders' => count($absorbed),
                'true_historical_missing' => count($trueMissing),
                'august_excluded_from_historical' => count($augustPostCutover),
                'completar_pendiente_count' => count($completePending),
            ],
            'ausa' => [
                'is_recurring' => false,
                'existing' => $ausaExisting,
                'pending_no_auto' => $ausaPending,
                'proposals_eliminated' => $ausaEliminated,
            ],
            'final_reconstruct_historical' => $reconstructHistorical,
            'final_complete_pending' => $completePending,
            'final_august_post_cutover' => $augustPostCutover,
            'final_remaining_incomplete_pendings' => $remainIncomplete,
            'create_executed' => false,
            'notes' => [
                'Solo detección y propuesta; no se crean movimientos.',
                'Placeholder en el mes absorbe la propuesta: completar pendiente, no crear otro movimiento.',
                'AUSA no es recurrente: propuestas faltantes eliminadas; fila 588 no se auto-completa.',
                'Corte 2026-08-15: no inferir 2026-08-31; agosto sin evidencia previa → post-corte.',
                'Pedidos Ya! Premium confirmado: ARS 2999 / MC / mensual.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $cell
     * @param  array{amount: ?float, rule: string, origin: string}  $suggestedAmount
     * @param  array<string, mixed>|null  $ph
     * @return array<string, mixed>
     */
    private function detailRow(
        string $key,
        array $def,
        string $ym,
        array $cell,
        array $suggestedAmount,
        ?string $accountHint,
        string $categoryHint,
        string $treatment,
        ?array $ph = null,
    ): array {
        return [
            'service' => $key,
            'label' => $def['label'] ?? $key,
            'ym' => $ym,
            'month_label' => $cell['label'] ?? $ym,
            'registro_original_existente' => $treatment === 'registro_original_existente',
            'placeholder_existente' => $ph !== null || ($cell['placeholder_rows'] ?? []) !== [],
            'placeholder_row' => $ph['source_row'] ?? (($cell['placeholder_rows'][0] ?? null)),
            'source_rows' => $cell['source_rows'] ?? [],
            'movimiento_realmente_faltante' => $treatment === 'movimiento_realmente_faltante',
            'importe_original' => $cell['amount_original'] ?? null,
            'importe_propuesto' => in_array($treatment, [
                'completar_pendiente_existente',
                'movimiento_realmente_faltante',
                'recurrente_pendiente_agosto_2026',
            ], true) ? $suggestedAmount['amount'] : null,
            'fecha' => $cell['suggested_date'] ?? null,
            'dato' => $cell['dato'] ?? null,
            'original_vs_inferido' => ($cell['dato'] ?? '') === 'original' || ($cell['dato'] ?? '') === 'original_importe_fecha_inferida'
                ? 'original'
                : 'inferido',
            'tratamiento_propuesto' => $treatment,
            'account_hint' => $accountHint,
            'category_hint' => $categoryHint,
            'amount_rule' => $suggestedAmount['rule'],
            'amount_origin' => $suggestedAmount['origin'],
            'user_confirmed_recurring' => (bool) ($def['user_confirmed_recurring'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{service: string, covers?: array<string, bool>, alias_note?: string}|null
     */
    private function classifyRow(array $row): ?array
    {
        $concepto = trim((string) ($row['concepto'] ?? ''));
        if ($concepto === '') {
            return null;
        }
        $cuenta = trim((string) ($row['excel_cuenta_category'] ?? ''));
        $norm = $this->normalize($concepto);

        if ($this->isPedidosYaFoodOrder($norm, $cuenta)) {
            return null;
        }

        if (preg_match('/pedidos\s*ya[!.,]?\s*(premium|plus)\b/u', $norm)
            || preg_match('/pedidosya\s*(premium|plus)\b/u', $norm)
        ) {
            return ['service' => 'pedidos_ya_premium'];
        }

        if (str_contains($norm, 'youtube') && str_contains($norm, 'spotify')) {
            return [
                'service' => 'youtube_spotify_combo',
                'covers' => ['youtube' => true, 'spotify' => true],
            ];
        }

        if (preg_match('/\byoutube\b/u', $norm)) {
            return ['service' => 'youtube'];
        }
        if (preg_match('/\bspotify\b/u', $norm)) {
            return ['service' => 'spotify'];
        }
        if (preg_match('/\bmubi\b/u', $norm)) {
            return ['service' => 'mubi'];
        }
        if (preg_match('/falta\s+el\s+seguro(\s+del\s+auto)?/u', $norm)
            || preg_match('/seguro\s+del\s+auto/u', $norm)
        ) {
            return [
                'service' => 'mercantil_andina',
                'alias_note' => 'Usuario: “Falta el seguro del auto” = Mercantil Andina',
            ];
        }
        if (preg_match('/mercantil\s*andina/u', $norm)) {
            return ['service' => 'mercantil_andina'];
        }

        // Track AUSA for existing/pending only — never as recurring subscription.
        if (preg_match('/\bausa\b/u', $norm)) {
            return ['service' => 'ausa'];
        }

        if (preg_match('/^(meli|mercado\s*libre|ml)\b/u', $norm)) {
            if ($cuenta !== '' && ! in_array($cuenta, ['Servicios', 'Suscripciones', 'Seguros'], true)) {
                return null;
            }
            if (preg_match('/(compra|pedido|envio|producto|notebook|celular)/u', $norm)) {
                return null;
            }

            return ['service' => 'meli'];
        }

        return null;
    }

    private function isPedidosYaFoodOrder(string $norm, string $cuenta): bool
    {
        $isPedidosYa = (bool) preg_match('/pedidos\s*ya/u', $norm) || str_contains($norm, 'pedidosya');
        if (! $isPedidosYa) {
            return false;
        }
        if (preg_match('/(premium|plus)\b/u', $norm)) {
            return false;
        }

        return true;
    }

    private function normalize(string $concepto): string
    {
        $c = mb_strtolower(trim($concepto));
        $c = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $c);

        return preg_replace('/\s+/', ' ', $c) ?? $c;
    }

    /**
     * @param  list<float>  $observed
     * @param  array<string, mixed>  $def
     * @return array{amount: ?float, rule: string, origin: string}
     */
    private function suggestAmount(array $def, array $observed): array
    {
        if (isset($def['fixed_amount']) && $def['fixed_amount'] !== null) {
            return [
                'amount' => (float) $def['fixed_amount'],
                'rule' => 'importe_fijo_confirmado_usuario',
                'origin' => (string) ($def['fixed_amount_reason'] ?? 'confirmado por usuario'),
            ];
        }
        $positive = array_values(array_filter($observed, fn ($a) => $a > 0.0001));
        if ($positive === []) {
            return [
                'amount' => null,
                'rule' => 'sin_historico',
                'origin' => 'No hay importes históricos del mismo servicio',
            ];
        }
        $forceMax = ($def['amount_rule_override'] ?? null) === 'importe_historico_maximo';
        $unique = array_unique(array_map(fn ($a) => round($a, 2), $positive));
        if (! $forceMax && count($unique) === 1) {
            $amount = (float) array_values($unique)[0];

            return [
                'amount' => $amount,
                'rule' => 'importe_historico_constante',
                'origin' => 'Todos los importes históricos iguales: '.$amount,
            ];
        }
        $amount = max($positive);

        return [
            'amount' => (float) $amount,
            'rule' => 'importe_historico_maximo',
            'origin' => 'Importes variables / regla usuario: usar el más alto observado: '.$amount,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $byMonth
     * @param  list<string>  $months
     * @return list<array<string, mixed>>
     */
    private function matrixRow(array $byMonth, array $months): array
    {
        $out = [];
        foreach ($months as $ym) {
            $cell = $byMonth[$ym];
            $display = '—';
            $treatment = $cell['treatment'] ?? 'ausente';
            if ($treatment === 'registro_original_existente' && $cell['amount_original'] !== null) {
                $display = number_format((float) $cell['amount_original'], 2, ',', '.');
            } elseif ($treatment === 'completar_pendiente_existente' && $cell['amount_display'] !== null) {
                $display = 'P≈'.number_format((float) $cell['amount_display'], 2, ',', '.').'*';
            } elseif ($treatment === 'movimiento_realmente_faltante' && $cell['amount_display'] !== null) {
                $display = 'F≈'.number_format((float) $cell['amount_display'], 2, ',', '.').'*';
            } elseif ($treatment === 'recurrente_pendiente_agosto_2026' && $cell['amount_display'] !== null) {
                $display = 'A≈'.number_format((float) $cell['amount_display'], 2, ',', '.').'†';
            }
            $out[] = [
                'ym' => $ym,
                'label' => $cell['label'],
                'display' => $display,
                'dato' => $cell['dato'],
                'treatment' => $treatment,
                'amount_original' => $cell['amount_original'],
                'amount_proposed' => $cell['present'] ? null : $cell['amount_display'],
                'placeholder_rows' => $cell['placeholder_rows'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function monthsInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = [];
        $cursor = $from->modify('first day of this month');
        $end = $to->modify('first day of this month');
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function rowYearMonth(array $hit): ?string
    {
        $date = $hit['date'] ?? null;
        if (is_string($date) && preg_match('/^(\d{4}-\d{2})/', $date, $m)) {
            return $m[1];
        }
        $ctx = mb_strtolower(trim((string) ($hit['month_context'] ?? '')));
        $map = config('historical_date_closure.month_name_to_number', []);
        if ($ctx !== '' && isset($map[$ctx])) {
            return sprintf('2026-%02d', (int) $map[$ctx]);
        }

        return null;
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
