<?php

namespace App\Console\Commands;

use App\Services\Finance\ExchangeRateService;
use Illuminate\Console\Command;
use Throwable;

class UpdateExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:update';

    protected $description = 'Consulta DolarAPI (oficial venta/compra) y guarda la cotización en el histórico local sin sobrescribir.';

    public function handle(ExchangeRateService $rates): int
    {
        try {
            $result = $rates->updateOfficialFromProvider();
            $this->info($result['message']);
            $this->line(sprintf(
                'Venta: %s | Compra: %s | Fecha: %s | Provider: %s',
                $result['rate']->rate,
                $result['rate']->rate_buy ?? '—',
                $result['rate']->rate_at?->toDateTimeString(),
                $result['rate']->provider
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
