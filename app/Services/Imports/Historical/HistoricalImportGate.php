<?php

namespace App\Services\Imports\Historical;

use App\Enums\ImportReviewStatus;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

/**
 * Gate pre-import Etapa 11E (§8). Si falla cualquiera → no importar.
 */
class HistoricalImportGate
{
    /**
     * @return array{passed: bool, blockers: list<string>, semaforo: array<string, int>, details: array<string, mixed>}
     */
    public function evaluate(ImportBatch $batch): array
    {
        $payload = $batch->preview_payload ?? [];
        $summary = $payload['summary'] ?? $batch->classification_summary ?? [];
        $closure = $payload['authorized_closure'] ?? [];
        $recon = $payload['reconciliation'] ?? $batch->reconciliation_payload ?? [];

        $blockers = [];
        $yellow = (int) ($summary['yellow'] ?? $batch->rows_yellow ?? 0);
        $red = (int) ($summary['red'] ?? $batch->rows_red ?? 0);
        if ($yellow !== 0) {
            $blockers[] = "Amarillos = {$yellow} (debe ser 0)";
        }
        if ($red !== 0) {
            $blockers[] = "Rojos = {$red} (debe ser 0)";
        }

        $rows = $this->loadRows($batch);
        $pending = [];
        $excluded = [];
        $zeroAmountImportReady = [];
        $missingTraceRecon = [];
        foreach ($rows as $row) {
            $status = (string) ($row['review_status'] ?? '');
            $sr = (int) ($row['source_row'] ?? 0);
            if ($status === ImportReviewStatus::PendingComplete->value) {
                $pending[] = $sr;
            }
            if ($status === ImportReviewStatus::Excluded->value) {
                $excluded[] = $sr;
            }
            if (ImportReviewStatus::tryFrom($status)?->isImportReady()) {
                $exp = (float) ($row['interpretation']['finance_expense'] ?? 0);
                $inc = (float) ($row['interpretation']['finance_income'] ?? 0);
                $ccIn = (float) ($row['interpretation']['cc_charge'] ?? 0);
                $ccOut = (float) ($row['interpretation']['cc_payment'] ?? 0);
                $venta = (float) ($row['interpretation']['economic_venta'] ?? 0);
                if ($exp <= 0.0001 && $inc <= 0.0001 && $ccIn <= 0.0001 && $ccOut <= 0.0001 && $venta <= 0.0001
                    && empty($row['interpretation']['is_opening_adjustment'])
                    && ($row['interpretation']['kind'] ?? '') !== 'saldo_apertura_mercaderia'
                ) {
                    // allow pure analysis rows? still skip import; flag if marked import-ready with nothing
                    if (($row['amounts']['egresos'] ?? 0) <= 0.0001
                        && ($row['amounts']['ingresos'] ?? 0) <= 0.0001
                        && ($row['amounts']['cc_in'] ?? 0) <= 0.0001
                        && ($row['amounts']['cc_out'] ?? 0) <= 0.0001
                        && ($row['amounts']['venta'] ?? 0) <= 0.0001
                        && ($row['amounts']['pagos_tc'] ?? 0) <= 0.0001
                    ) {
                        $zeroAmountImportReady[] = $sr;
                    }
                }
            }
            if (! empty($row['is_synthetic_reconstruction'])) {
                $trace = $row['inference_trace'] ?? [];
                if (($trace['origen'] ?? '') !== (string) config('historical_closure_11e.reconstruction_origen')) {
                    $missingTraceRecon[] = $sr;
                }
            }
        }

        $allowedPending = array_map('intval', array_keys(config('historical_closure_11e.non_importable_pendings', [])));
        sort($pending);
        sort($allowedPending);
        $unexpectedPending = array_values(array_diff($pending, $allowedPending));
        $missingAllowed = array_values(array_diff($allowedPending, $pending));
        if ($unexpectedPending !== []) {
            $blockers[] = 'Pendientes no importables inesperados: '.implode(', ', $unexpectedPending)
                .' (únicos permitidos: '.implode(', ', $allowedPending).')';
        }
        if ($missingAllowed !== []) {
            $blockers[] = 'Faltan pendientes conocidos no importables: '.implode(', ', $missingAllowed);
        }

        if ($missingTraceRecon !== []) {
            $blockers[] = 'Reconstrucciones sin trazabilidad origen: '.implode(', ', $missingTraceRecon);
        }

        // Diferencias inexplicadas
        $unexplained = $recon['unexplained_differences'] ?? $payload['difference_attribution']['unexplained'] ?? null;
        if (is_array($unexplained) && $unexplained !== []) {
            $blockers[] = 'Diferencias inexplicadas en conciliación: '.json_encode($unexplained, JSON_UNESCAPED_UNICODE);
        }
        if (($recon['has_unexplained_differences'] ?? false) === true) {
            $blockers[] = 'Flag has_unexplained_differences=true';
        }

        // Duplicaciones financieras detectadas
        $dupGuards = $payload['sale_semantics_report']['duplicate_income_guards'] ?? [];
        $dupProblems = [];
        if (is_array($dupGuards)) {
            foreach ($dupGuards as $g) {
                if (! empty($g['blocked']) || ! empty($g['duplicate_detected'])) {
                    $dupProblems[] = $g;
                }
            }
        }
        if ($dupProblems !== []) {
            $blockers[] = 'Duplicaciones financieras detectadas: '.count($dupProblems);
        }

        $closureSkipped = $closure['skipped'] ?? [];
        foreach ($closureSkipped as $s) {
            if (($s['kind'] ?? '') === 'reconstruction_amount_mismatch') {
                $blockers[] = 'Mismatch importe reconstrucción: '.json_encode($s, JSON_UNESCAPED_UNICODE);
            }
        }

        $createdRecon = count($closure['reconstructions_created'] ?? []);
        $expectedRecon = count(config('historical_closure_11e.authorized_reconstructions', []));
        $avoided = count(array_filter(
            $closureSkipped,
            fn ($s) => ($s['kind'] ?? '') === 'reconstruction_duplicate_avoided'
        ));
        if ($createdRecon + $avoided !== $expectedRecon) {
            $blockers[] = "Reconstrucciones: creadas={$createdRecon} evitadas_dup={$avoided} esperadas={$expectedRecon}";
        }

        $completed = count($closure['placeholders_completed'] ?? []);
        $expectedCompleted = count(config('historical_closure_11e.placeholder_completions', []));
        if ($completed !== $expectedCompleted) {
            $blockers[] = "Placeholders completados={$completed} esperados={$expectedCompleted}";
        }

        $exclRedundant = count($closure['placeholders_excluded'] ?? []);
        $expectedExcl = count(config('historical_closure_11e.redundant_placeholder_exclusions', []));
        if ($exclRedundant !== $expectedExcl) {
            $blockers[] = "Exclusiones redundantes={$exclRedundant} esperadas={$expectedExcl}";
        }

        // Idempotencia / batch identificable
        if (! $batch->uuid) {
            $blockers[] = 'import_batch sin uuid identificable';
        }
        if (($batch->importer_kind ?? '') !== 'historical_movements') {
            $blockers[] = 'importer_kind inválido para cierre histórico';
        }
        if ($batch->isConfirmed()) {
            $blockers[] = 'Batch ya confirmado — protección contra 2ª ejecución (usar rollback o nuevo batch)';
        }

        if ($zeroAmountImportReady !== []) {
            // soft: no blocker if they won't be imported; record in details
        }

        $semaforo = [
            'green' => (int) ($summary['green'] ?? 0),
            'inferred' => (int) ($summary['inferred'] ?? 0),
            'corrected' => (int) ($summary['corrected'] ?? 0),
            'yellow' => $yellow,
            'red' => $red,
            'pending_complete' => (int) ($summary['pending_complete'] ?? count($pending)),
            'excluded' => (int) ($summary['excluded'] ?? count($excluded)),
        ];

        return [
            'passed' => $blockers === [],
            'blockers' => $blockers,
            'semaforo' => $semaforo,
            'details' => [
                'pending_rows' => $pending,
                'excluded_rows' => $excluded,
                'zero_amount_import_ready' => $zeroAmountImportReady,
                'closure' => [
                    'placeholders_completed' => $completed,
                    'placeholders_excluded' => $exclRedundant,
                    'reconstructions_created' => $createdRecon,
                    'reconstructions_avoided' => $avoided,
                    'monetary_completed' => $closure['monetary_completed'] ?? null,
                    'monetary_reconstructed' => $closure['monetary_reconstructed'] ?? null,
                ],
                'batch_uuid' => $batch->uuid,
                'file_hash' => $batch->file_hash,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(ImportBatch $batch): array
    {
        $path = $batch->preview_payload['rows_all_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return [];
        }
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }
        $json = json_decode(Storage::disk('local')->get($path), true);

        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }
}
