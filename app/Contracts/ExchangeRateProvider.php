<?php

namespace App\Contracts;

use App\DTO\ExternalExchangeQuote;

interface ExchangeRateProvider
{
    public function name(): string;

    /**
     * Cotización oficial de venta USD→ARS (ARS por 1 USD).
     */
    public function fetchOfficialSellUsdArs(): ExternalExchangeQuote;
}
