<?php

namespace App\Integrations\ExchangeRates;

use App\DTO\ExternalExchangeQuote;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Histórico USD/ARS oficial (BNA) vía ArgentinaDatos.
 * Separado de DolarAPI (cotización vigente).
 */
class ArgentinaDatosHistoricalProvider
{
    public function name(): string
    {
        return 'argentinadatos';
    }

    /**
     * Serie histórica completa (casa oficial).
     *
     * @return list<array{fecha: string, compra: ?string, venta: string}>
     */
    public function fetchOfficialHistory(): array
    {
        $base = rtrim((string) config('finance.argentinadatos.base_url'), '/');
        $path = (string) config('finance.argentinadatos.oficial_path', '/cotizaciones/dolares/oficial');
        $timeout = (int) config('finance.argentinadatos.timeout', 30);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($base.$path)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('ArgentinaDatos no disponible: '.$e->getMessage(), previous: $e);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Respuesta inválida de ArgentinaDatos (histórico).');
        }

        $rows = [];
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            $fecha = (string) ($item['fecha'] ?? '');
            $venta = $item['venta'] ?? null;
            if ($fecha === '' || ! is_numeric($venta)) {
                continue;
            }
            $compra = isset($item['compra']) && is_numeric($item['compra']) ? (string) $item['compra'] : null;
            $rows[] = [
                'fecha' => Carbon::parse($fecha)->toDateString(),
                'compra' => $compra,
                'venta' => (string) $venta,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return $rows;
    }

    public function fetchOfficialForDate(Carbon|string $date): ExternalExchangeQuote
    {
        $day = Carbon::parse($date);
        $base = rtrim((string) config('finance.argentinadatos.base_url'), '/');
        $timeout = (int) config('finance.argentinadatos.timeout', 30);
        $url = sprintf(
            '%s/cotizaciones/dolares/oficial/%s',
            $base,
            $day->format('Y/m/d')
        );

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url)
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('ArgentinaDatos sin cotización para '.$day->toDateString().': '.$e->getMessage(), previous: $e);
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['venta']) || ! is_numeric($data['venta'])) {
            throw new RuntimeException('Respuesta inválida de ArgentinaDatos para '.$day->toDateString());
        }

        $buy = isset($data['compra']) && is_numeric($data['compra']) ? (string) $data['compra'] : null;

        return new ExternalExchangeQuote(
            rate: (string) $data['venta'],
            rateAt: Carbon::parse($data['fecha'] ?? $day)->startOfDay()->toIso8601String(),
            provider: $this->name(),
            payload: $data,
            rateType: 'official_sell',
            buyRate: $buy,
        );
    }
}
