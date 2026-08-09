<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class GenerateSubscriptionChargesCommand extends Command
{
    protected $signature = 'subscriptions:generate {--date= : Fecha de corte Y-m-d}';

    protected $description = 'Genera cargos de abonos activos vencidos (idempotente por período).';

    public function handle(SubscriptionService $subscriptions): int
    {
        $date = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))
            : now();

        $count = $subscriptions->generateDue($date);
        $this->info("Cargos generados: {$count}");

        return self::SUCCESS;
    }
}
