<?php

namespace App\Console\Commands;

use App\Services\Finance\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class BackfillExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:backfill
                            {--from=2026-01-01 : Fecha desde (Y-m-d)}
                            {--to= : Fecha hasta (Y-m-d, default hoy)}
                            {--preview : Solo muestra preview sin importar}';

    protected $description = 'Backfill histórico USD/ARS oficial (BNA) desde ArgentinaDatos. Idempotente; no inventa fines de semana.';

    public function handle(ExchangeRateService $rates): int
    {
        try {
            $from = Carbon::parse((string) $this->option('from'));
            $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : null;

            $preview = $rates->previewArgentinaDatosBackfill($from, $to);
            $this->info(sprintf(
                'Preview %s → %s | API: %d | a importar: %d | ya presentes: %d',
                $preview['from'],
                $preview['to'],
                $preview['api_rows'],
                $preview['to_import'],
                $preview['already_present']
            ));
            $this->line($preview['weekend_note']);

            if ($this->option('preview')) {
                foreach ($preview['sample'] as $row) {
                    $this->line(sprintf('  %s compra=%s venta=%s', $row['fecha'], $row['compra'] ?? '—', $row['venta']));
                }

                return self::SUCCESS;
            }

            $result = $rates->backfillFromArgentinaDatos($from, $to);
            $this->info(sprintf(
                'Backfill OK: importadas %d, omitidas %d (%s → %s)',
                $result['imported'],
                $result['skipped'],
                $result['from'],
                $result['to']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
