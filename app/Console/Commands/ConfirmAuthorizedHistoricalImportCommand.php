<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Imports\Historical\HistoricalImportGate;
use App\Services\Imports\Historical\HistoricalMovementsConfirmService;
use App\Services\Imports\Historical\HistoricalMovementsPreviewService;
use App\Services\Imports\Historical\StagingTestDataCleanupService;
use Illuminate\Console\Command;

class ConfirmAuthorizedHistoricalImportCommand extends Command
{
    protected $signature = 'imports:confirm-historical-authorized
                            {--movements= : Ruta Excel GASTOS MENSUALES}
                            {--batch= : UUID o ID de batch preview existente}
                            {--token= : Token de autorización 11E}
                            {--user= : User ID}
                            {--skip-cleanup : No ejecutar 6B}
                            {--cleanup-only : Solo 6B diagnose+purge}
                            {--gate-only : Solo evaluar gate}
                            {--out= : Reporte JSON}';

    protected $description = 'Cierre 11E: cleanup 6B + preview/gate + import autorizado a DB actual.';

    public function handle(
        HistoricalMovementsPreviewService $preview,
        HistoricalMovementsConfirmService $confirm,
        HistoricalImportGate $gate,
        StagingTestDataCleanupService $cleanup,
    ): int {
        $out = $this->option('out') ?: storage_path('app/imports/reports/stage11e-close-'.now()->format('Ymd-His').'.json');
        $token = (string) ($this->option('token') ?: config('historical_closure_11e.authorization_token'));
        $userId = (int) ($this->option('user') ?: User::query()->orderBy('id')->value('id'));
        $report = [
            'started_at' => now()->toDateTimeString(),
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'db' => config('database.default'),
        ];

        if (! $this->option('skip-cleanup') || $this->option('cleanup-only')) {
            $this->info('6B diagnóstico...');
            $diag = $cleanup->diagnose();
            $report['cleanup_6b'] = ['diagnose' => $diag];
            if ($diag['stop_required']) {
                $report['blocked'] = '6B clase D dudosa — no purge ni import';
                $this->writeReport($out, $report);
                $this->error($report['blocked']);

                return self::FAILURE;
            }
            if (($diag['counts']['A_manual_test'] ?? 0) > 0) {
                $this->warn('6B purgando clase A...');
                $report['cleanup_6b']['purge'] = $cleanup->purgeClassA(false);
            } else {
                $this->info('6B: sin clase A que purgar.');
                $report['cleanup_6b']['purge'] = null;
            }
            $report['cleanup_6b']['post'] = $cleanup->postCleanupChecks();
            if ($this->option('cleanup-only')) {
                $this->writeReport($out, $report);
                $this->info('Cleanup-only OK. '.$out);

                return self::SUCCESS;
            }
        }

        $batch = null;
        if ($id = $this->option('batch')) {
            $batch = ImportBatch::query()
                ->when(is_numeric($id), fn ($q) => $q->where('id', $id), fn ($q) => $q->where('uuid', $id))
                ->first();
            if (! $batch) {
                $this->error('Batch no encontrado');

                return self::FAILURE;
            }
            $this->info('Reprocesando batch #'.$batch->id);
            $batch = $preview->reprocess($batch);
        } else {
            $path = $this->option('movements');
            if (! $path || ! is_file($path)) {
                $this->error('Indicar --movements= o --batch=');

                return self::FAILURE;
            }
            $this->info('Generando preview con cierre autorizado...');
            $batch = $preview->analyzePath($path, basename($path), $userId);
        }

        $report['batch'] = [
            'id' => $batch->id,
            'uuid' => $batch->uuid,
            'summary' => $batch->classification_summary,
            'authorized_closure' => $batch->preview_payload['authorized_closure'] ?? [],
            'reconciliation_bridge' => $batch->reconciliation_payload['authorized_closure_bridge'] ?? [],
        ];

        $gateResult = $gate->evaluate($batch->fresh());
        $report['gate'] = $gateResult;
        $this->line('Gate: '.($gateResult['passed'] ? 'PASS' : 'FAIL'));
        if (! $gateResult['passed']) {
            foreach ($gateResult['blockers'] as $b) {
                $this->error('BLOQUEO: '.$b);
            }
            $this->writeReport($out, $report);
            $this->warn('NO SE IMPORTÓ.');

            return self::FAILURE;
        }

        if ($this->option('gate-only')) {
            $this->writeReport($out, $report);
            $this->info('Gate-only PASS. '.$out);

            return self::SUCCESS;
        }

        $this->warn('Importando con autorización 11E...');
        try {
            $result = $confirm->confirmAuthorizedHistoricalImport($batch->fresh(), $token, $userId);
            $report['import'] = $result['import'];
            $report['batch_confirmed'] = [
                'id' => $result['batch']->id,
                'uuid' => $result['batch']->uuid,
                'status' => $result['batch']->status,
                'rows_imported' => $result['batch']->rows_imported,
            ];
        } catch (\Throwable $e) {
            $report['import_error'] = $e->getMessage();
            $this->writeReport($out, $report);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->writeReport($out, $report);
        $this->info('Import OK batch='.$result['batch']->uuid);
        $this->line('Reporte: '.$out);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(string $out, array $report): void
    {
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        $report['finished_at'] = now()->toDateTimeString();
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
