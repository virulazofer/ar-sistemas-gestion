<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProvider;
use App\Integrations\ExchangeRates\DolarApiProvider;
use App\Models\Movement;
use App\Policies\MovementPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExchangeRateProvider::class, DolarApiProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(Movement::class, MovementPolicy::class);
    }
}
