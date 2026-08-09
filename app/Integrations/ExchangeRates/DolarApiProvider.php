<?php

namespace App\Integrations\ExchangeRates;

use App\Contracts\ExchangeRateProvider;
use App\DTO\ExternalExchangeQuote;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DolarApiProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'dolarapi';
    }

    public function fetchOfficialSellUsdArs(): ExternalExchangeQuote
    {
        $base = rtrim((string) config('finance.dolarapi.base_url'), '/');
        $path = (string) config('finance.dolarapi.official_path');
        $url = $base.$path;
        $sellField = (string) config('finance.dolarapi.preferred_field', 'venta');
        $buyField = (string) config('finance.dolarapi.buy_field', 'compra');
        $timeout = (int) config('finance.dolarapi.timeout', 8);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('DolarAPI no disponible: '.$e->getMessage(), previous: $e);
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data[$sellField]) || ! is_numeric($data[$sellField])) {
            throw new RuntimeException('Respuesta inválida de DolarAPI.');
        }

        $buyRate = null;
        if (isset($data[$buyField]) && is_numeric($data[$buyField])) {
            $buyRate = (string) $data[$buyField];
        }

        $rateAt = $data['fechaActualizacion'] ?? now()->toIso8601String();

        return new ExternalExchangeQuote(
            rate: (string) $data[$sellField],
            rateAt: $rateAt,
            provider: $this->name(),
            payload: $data,
            rateType: 'official_sell',
            buyRate: $buyRate,
        );
    }
}
