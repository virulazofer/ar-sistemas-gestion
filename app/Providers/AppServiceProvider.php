<?php

namespace App\Providers;

use App\Contracts\DocumentAnalysisService;
use App\Contracts\ExchangeRateProvider;
use App\Integrations\ExchangeRates\DolarApiProvider;
use App\Models\Document;
use App\Models\Movement;
use App\Policies\DocumentPolicy;
use App\Policies\MovementPolicy;
use App\Services\Documents\NullDocumentAnalysisService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExchangeRateProvider::class, DolarApiProvider::class);
        $this->app->bind(DocumentAnalysisService::class, NullDocumentAnalysisService::class);
    }

    public function boot(): void
    {
        Gate::policy(Movement::class, MovementPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
