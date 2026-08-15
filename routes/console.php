<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cotización oficial actual (DolarAPI). Histórico acumulado en exchange_rates.
// Cron del hosting (aún no crear en staging): * * * * * php artisan schedule:run
Schedule::command('exchange-rates:update')->hourly()->withoutOverlapping();

// Cargos de abonos activos vencidos (idempotente por período).
Schedule::command('subscriptions:generate')->daily()->withoutOverlapping();

// Temporales de captura documental (34B).
Schedule::command('documents:cleanup-temp')->daily()->withoutOverlapping();
