<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;

/**
 * Estado operativo del preview (listo vs requiere decisión humana).
 * No altera importes, fechas, CC ni ámbitos — solo reclasifica el semáforo.
 */
class HistoricalOperationalStatusClassifier
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *   status: ImportReviewStatus,
     *   reason: string,
     *   needs_human: bool,
     *   human_options: list<string>,
     *   prior_review_status: string|null
     * }
     */
    public function classify(array $row): array
    {
        $prior = $row['review_status'] ?? null;
        $flags = $row['flags'] ?? [];
        $cause = (string) ($row['root_cause'] ?? '');
        $interp = $row['interpretation'] ?? [];

        if ($prior === ImportReviewStatus::Excluded->value || $cause === 'excluida') {
            return $this->out(ImportReviewStatus::Excluded, 'Excluida deliberadamente', false, [], $prior);
        }
        if ($prior === ImportReviewStatus::PendingComplete->value
            || in_array('pendiente_completar', $flags, true)
            || in_array('importe_pago_tarjeta_desconocido', $flags, true)
            || $cause === 'importe_pago_tarjeta_desconocido'
        ) {
            if (in_array('importe_pago_tarjeta_desconocido', $flags, true)
                || $cause === 'importe_pago_tarjeta_desconocido'
            ) {
                return $this->out(
                    ImportReviewStatus::PendingComplete,
                    'Pago de resumen confirmado; importe desconocido — no inventar; no import-ready',
                    false,
                    [],
                    $prior
                );
            }

            return $this->out(
                ImportReviewStatus::PendingComplete,
                'Anotación incompleta — falta fecha y/o importe (y posiblemente categoría/medio)',
                true,
                ['Completar datos', 'Excluir', 'Convertir desde propuesta recurrente (si aplica)'],
                $prior
            );
        }
        if ($prior === ImportReviewStatus::Red->value) {
            return $this->out(ImportReviewStatus::Red, 'Bloqueo / error de importación', true, ['Corregir', 'Excluir'], $prior);
        }

        // --- Inferido (regla aprobada de reconstrucción) ---
        if (in_array('fecha_inferida_cierre_mensual', $flags, true)
            || ! empty($row['date_inferred_month_end'])
        ) {
            // If ONLY inference flags (plus informational), ready as inferred
            if ($this->onlyInformationalOr($flags, [
                'fecha_inferida_cierre_mensual', 'cc_movimiento', 'ambito_dudoso',
                'pago_tarjeta_posible', // shouldn't coexist usually
                'pago_resumen_tarjeta_confirmado',
            ]) || $cause === 'fecha_inferida_cierre_mensual') {
                return $this->out(
                    ImportReviewStatus::Inferred,
                    'Fecha inferida por cierre mensual (regla aprobada); listo con trazabilidad',
                    false,
                    [],
                    $prior
                );
            }
        }

        // --- Saldo / ajuste de apertura CC confirmado explícitamente ---
        if (in_array('cc_apertura_confirmada', $flags, true)
            || in_array('confirmed_opening_cc_balance', $flags, true)
            || ($interp['kind'] ?? '') === 'saldo_apertura_cc'
            || $cause === 'cc_apertura_confirmada'
        ) {
            return $this->out(
                ImportReviewStatus::Corrected,
                'Saldo de apertura de CC confirmado (deuda inicial cliente); listo con trazabilidad',
                false,
                [],
                $prior
            );
        }

        // --- Saldo / ajuste de apertura mercadería confirmado ---
        if (in_array('cc_apertura_mercaderia_confirmada', $flags, true)
            || in_array('confirmed_opening_merca_balance', $flags, true)
            || ($interp['kind'] ?? '') === 'saldo_apertura_mercaderia'
            || $cause === 'cc_apertura_mercaderia_confirmada'
        ) {
            return $this->out(
                ImportReviewStatus::Corrected,
                'Saldo de apertura de mercadería confirmado; listo con trazabilidad (sin stock/caja)',
                false,
                [],
                $prior
            );
        }

        // --- Cancelación CC / cobro / cliente CC confirmados ---
        if (in_array('cc_cancelacion_daasa_confirmada', $flags, true)
            || in_array('cliente_cintas_confirmado', $flags, true)
            || in_array('cc_in_inferido_cintas', $flags, true)
            || in_array('cobro_confirmado_patagonia', $flags, true)
            || in_array('cobro_confirmado_ft', $flags, true)
            || in_array($interp['kind'] ?? '', [
                'cc_cancelacion_con_cobro',
                'cc_cancelacion_deuda',
                'cc_cargo_cliente',
            ], true)
        ) {
            if (! $this->hasBlockingHumanNeed($flags, $cause)) {
                return $this->out(
                    ImportReviewStatus::Corrected,
                    'CC/venta/cobro resuelto por decisión explícita del usuario; listo con trazabilidad',
                    false,
                    [],
                    $prior
                );
            }
        }

        // --- Corregido (decisión explícita sobre dato inconsistente) ---
        if (in_array('fecha_aplicada_preview', $flags, true)
            || in_array('valor_historico_corregido_por_interpretacion', $flags, true)
            || in_array($cause, [
                'fecha_aplicada_preview',
                'venta_cc_corregida_credito_cancelado',
                'venta_cc_corregida_por_interpretacion',
                'cc_apertura_mercaderia_confirmada',
                'cc_cancelacion_confirmada',
                'venta_cobro_confirmado',
            ], true)
        ) {
            if (! $this->hasBlockingHumanNeed($flags, $cause)) {
                return $this->out(
                    ImportReviewStatus::Corrected,
                    'Dato original corregido por decisión/regla explícita del usuario; listo con trazabilidad',
                    false,
                    [],
                    $prior
                );
            }
        }

        // --- Pago de resumen tarjeta (regla aprobada): listo salvo excepciones reales ---
        if (in_array('pago_resumen_tarjeta_confirmado', $flags, true)
            || ($interp['kind'] ?? '') === 'card_statement_payment'
            || in_array($cause, ['pago_resumen_tarjeta', 'pago_tarjeta_resuelto'], true)
        ) {
            if (in_array('importe_pago_tarjeta_desconocido', $flags, true)) {
                return $this->out(
                    ImportReviewStatus::PendingComplete,
                    'Pago de resumen confirmado; importe desconocido — no inventar; no import-ready',
                    false,
                    [],
                    $prior
                );
            }
            if (in_array('pago_tarjeta_sin_importe', $flags, true)) {
                return $this->out(
                    ImportReviewStatus::Yellow,
                    'Pago de resumen sin importe documentado en pagos_tc — no inventar monto',
                    true,
                    [
                        'Completar importe real del pago de resumen',
                        'Indicar que el pago está documentado en otra fila',
                        'Excluir fila',
                    ],
                    $prior
                );
            }
            if (in_array('pago_tarjeta_sin_tarjeta', $flags, true)
                || in_array('pago_tarjeta_sin_cuenta_pago', $flags, true)
            ) {
                $missing = [];
                $options = [];
                if (in_array('pago_tarjeta_sin_tarjeta', $flags, true)) {
                    $missing[] = 'tarjeta (VISA / MC / MCMP)';
                    $options[] = 'Indicar tarjeta del pasivo (VISA / MC / MCMP)';
                }
                if (in_array('pago_tarjeta_sin_cuenta_pago', $flags, true)) {
                    $missing[] = 'cuenta desde la que se pagó (Patagonia / MP Fer / otra)';
                    $options[] = 'Indicar cuenta de pago (Patagonia / MP Fer / FT / otra)';
                }
                $options[] = 'Excluir fila';

                return $this->out(
                    ImportReviewStatus::Yellow,
                    'Pago de resumen confirmado; falta identificar '.implode(' y ', $missing),
                    true,
                    $options,
                    $prior
                );
            }

            if (! $this->hasBlockingHumanNeed($flags, $cause)) {
                return $this->out(
                    ImportReviewStatus::Corrected,
                    'Pago de resumen de tarjeta (regla aprobada): cancela pasivo, sin segundo gasto; listo',
                    false,
                    [],
                    $prior
                );
            }
        }

        // --- Amarillo real: requiere decisión humana ---
        if (in_array('pago_tarjeta_posible', $flags, true) || $cause === 'pago_tarjeta') {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Movimiento de tarjeta residual (compra con egresos): confirmar tratamiento',
                true,
                ['Compra con tarjeta (gasto+pasivo)', 'Excluir'],
                $prior
            );
        }

        if (in_array('cobro_desconocido', $flags, true)
            || in_array('cc_omitida_probable', $flags, true)
            || $cause === 'cc_omitida_probable'
            || $cause === 'venta_cobro_desconocido'
        ) {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Venta sin cobro documentado / posible CC omitida — definir cómo se cobró',
                true,
                ['Cobro en cuenta X', 'Deuda CC cliente', 'Excluir / revisar fila'],
                $prior
            );
        }

        if (in_array('cuenta_desconocida', $flags, true) || $cause === 'cuenta_desconocida') {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Subcuenta/medio no mapeado a cuenta financiera AR',
                true,
                ['Mapear alias a cuenta existente', 'Crear cuenta', 'Tratar como cliente CC'],
                $prior
            );
        }

        if (in_array('fecha_apertura_revision', $flags, true)
            || ($cause === 'fecha_corregible' && in_array('fecha_apertura_revision', $flags, true))
        ) {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Saldo/apertura de ejercicio — confirmar fecha y tratamiento (no auto-convertir a 2026 operativo)',
                true,
                ['Conservar como apertura', 'Excluir del histórico operativo', 'Asignar fecha manual'],
                $prior
            );
        }

        if ($cause === 'cc_simple_revision'
            && (($row['amounts']['venta'] ?? 0) <= 0)
            && (($row['amounts']['cc_out'] ?? 0) > 0)
        ) {
            return $this->out(
                ImportReviewStatus::Yellow,
                'CC OUT sin venta en la misma fila — confirmar si es cobro de deuda previa u otra operación',
                true,
                ['Cobro de CC cliente', 'Otro (especificar)', 'Excluir'],
                $prior
            );
        }

        if (in_array('ambito_dudoso', $flags, true) || $cause === 'ambito_dudoso') {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Ámbito Personal/Profesional aún dudoso',
                true,
                ['Personal', 'Profesional'],
                $prior
            );
        }

        if (in_array('cliente_ambiguo', $flags, true)) {
            return $this->out(
                ImportReviewStatus::Yellow,
                'Cliente ambiguo en movimiento CC',
                true,
                ['Elegir cliente', 'Crear alias'],
                $prior
            );
        }

        // --- Verde: interpretaciones aprobadas / informativas ---
        if (in_array('cc_combinado_ingreso', $flags, true) || $cause === 'cc_combinado_ingreso') {
            return $this->out(
                ImportReviewStatus::Green,
                'Regla inequívoca CC OUT + ingreso aplicada; listo',
                false,
                [],
                $prior
            );
        }

        if (in_array('merca_analisis_only', $flags, true) || $cause === 'merca_analisis') {
            return $this->out(
                ImportReviewStatus::Green,
                'Mercadería solo análisis (sin stock de apertura); listo con trazabilidad',
                false,
                [],
                $prior
            );
        }

        if (in_array('reintegro_gasto_personal', $flags, true) || $cause === 'reintegro_gasto_personal') {
            return $this->out(
                ImportReviewStatus::Green,
                'Reintegro personal interpretado (no inconsistencia); listo',
                false,
                [],
                $prior
            );
        }

        if (in_array($cause, [
            'venta_economica_reclasificada',
            'venta_credito_luego_cancelada',
            'venta_cc_corregida_credito_cancelado',
            'venta_cc_corregida_por_interpretacion',
        ], true) || in_array('venta_economica', $flags, true)) {
            // If still has unknown cash, already handled above
            if (in_array('valor_historico_corregido_por_interpretacion', $flags, true)) {
                return $this->out(
                    ImportReviewStatus::Corrected,
                    'Venta/CC corregida por interpretación confirmada; listo',
                    false,
                    [],
                    $prior
                );
            }

            return $this->out(
                ImportReviewStatus::Green,
                'Venta económica interpretada según semántica aprobada; listo',
                false,
                [],
                $prior
            );
        }

        // Default: if was yellow without a human-need pattern → promote to green
        if ($prior === ImportReviewStatus::Yellow->value) {
            return $this->out(
                ImportReviewStatus::Green,
                'Sin decisión humana pendiente residual; listo',
                false,
                [],
                $prior
            );
        }

        if ($prior === ImportReviewStatus::Green->value) {
            return $this->out(ImportReviewStatus::Green, 'Interpretable y listo', false, [], $prior);
        }

        // Fallback keep prior if known enum
        foreach (ImportReviewStatus::cases() as $case) {
            if ($case->value === $prior) {
                return $this->out($case, 'Estado previo conservado', $case === ImportReviewStatus::Yellow, [], $prior);
            }
        }

        return $this->out(ImportReviewStatus::Yellow, 'Revisión manual requerida', true, ['Revisar fila'], $prior);
    }

    /**
     * @param  list<string>  $flags
     * @param  list<string>  $allowed
     */
    private function onlyInformationalOr(array $flags, array $allowed): bool
    {
        foreach ($flags as $f) {
            if (! in_array($f, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $flags
     */
    private function hasBlockingHumanNeed(array $flags, string $cause): bool
    {
        return in_array('pago_tarjeta_posible', $flags, true)
            || in_array('pago_tarjeta_sin_importe', $flags, true)
            || in_array('pago_tarjeta_sin_tarjeta', $flags, true)
            || in_array('pago_tarjeta_sin_cuenta_pago', $flags, true)
            || in_array('cobro_desconocido', $flags, true)
            || in_array('cc_omitida_probable', $flags, true)
            || in_array('cuenta_desconocida', $flags, true)
            || in_array('cliente_ambiguo', $flags, true)
            || in_array('ambito_dudoso', $flags, true)
            || in_array('fecha_apertura_revision', $flags, true);
    }

    /**
     * @param  list<string>  $options
     * @return array{status: ImportReviewStatus, reason: string, needs_human: bool, human_options: list<string>, prior_review_status: string|null}
     */
    private function out(
        ImportReviewStatus $status,
        string $reason,
        bool $needsHuman,
        array $options,
        ?string $prior,
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'needs_human' => $needsHuman,
            'human_options' => $options,
            'prior_review_status' => $prior,
        ];
    }
}
