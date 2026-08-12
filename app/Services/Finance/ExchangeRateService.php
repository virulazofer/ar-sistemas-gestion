<?php

namespace App\Services\Finance;

use App\Contracts\ExchangeRateProvider;
use App\Integrations\ExchangeRates\ArgentinaDatosHistoricalProvider;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExchangeRateService
{
    public const SOURCE_API = 'api';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_HISTORICAL_IMPORT = 'historical_import';

    public const PROVIDER_DOLARAPI = 'dolarapi';

    public const PROVIDER_ARGENTINADATOS = 'argentinadatos';

    public const PROVIDER_MANUAL = 'manual';

    public const PROVIDER_HISTORICAL_IMPORT = 'historical_import';

    public function __construct(
        private readonly ExchangeRateProvider $provider,
        private readonly ArgentinaDatosHistoricalProvider $historicalProvider,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Consulta DolarAPI y guarda la cotización oficial actual (venta + compra si viene).
     * Idempotente: no duplica si ya existe el mismo provider + rate_at + venta (+ compra).
     * Ante error de API no modifica la última cotización válida.
     *
     * @return array{rate: ExchangeRate, created: bool, message: string}
     */
    public function updateOfficialFromProvider(): array
    {
        try {
            $quote = $this->provider->fetchOfficialSellUsdArs();
        } catch (\Throwable $e) {
            Log::warning('exchange-rates:update falló al consultar proveedor', [
                'provider' => $this->provider->name(),
                'error' => $e->getMessage(),
            ]);

            $latest = $this->queryLatest();
            throw new RuntimeException(
                'No se pudo actualizar la cotización: '.$e->getMessage().
                ($latest ? ' Se conserva la última válida ('.$latest->rate_at?->format('Y-m-d H:i').').' : ''),
                previous: $e
            );
        }

        $sell = Money::normalize($quote->rate, 6);
        $buy = $quote->buyRate !== null ? Money::normalize($quote->buyRate, 6) : null;
        $rateAt = Carbon::parse($quote->rateAt);

        $existing = $this->findDuplicate(
            provider: $quote->provider,
            rateAt: $rateAt,
            sell: $sell,
            buy: $buy,
            source: self::SOURCE_API,
        );

        if ($existing) {
            return [
                'rate' => $existing,
                'created' => false,
                'message' => 'Cotización ya registrada; sin cambios.',
            ];
        }

        $rate = $this->storeOfficialSell(
            rate: $sell,
            rateBuy: $buy,
            rateAt: $rateAt->toIso8601String(),
            source: self::SOURCE_API,
            provider: $quote->provider,
            payload: $quote->payload,
        );

        return [
            'rate' => $rate,
            'created' => true,
            'message' => 'Cotización oficial guardada en histórico.',
        ];
    }

    public function syncOfficialFromProvider(): ExchangeRate
    {
        return $this->updateOfficialFromProvider()['rate'];
    }

    public function storeManual(string $rate, ?string $notes = null, ?string $rateBuy = null): ExchangeRate
    {
        return $this->storeOfficialSell(
            rate: Money::normalize($rate, 6),
            rateBuy: $rateBuy !== null && $rateBuy !== '' ? Money::normalize($rateBuy, 6) : null,
            rateAt: now()->toIso8601String(),
            source: self::SOURCE_MANUAL,
            provider: self::PROVIDER_MANUAL,
            payload: null,
            notes: $notes,
            createdBy: Auth::id(),
        );
    }

    /**
     * Importación histórica diaria. No sobrescribe filas existentes ni altera movimientos.
     */
    public function storeHistoricalImport(
        Carbon|string $date,
        string $sell,
        ?string $buy = null,
        ?string $notes = null,
        ?int $createdBy = null,
    ): ExchangeRate {
        $rateAt = Carbon::parse($date)->startOfDay();
        $sellNorm = Money::normalize($sell, 6);
        $buyNorm = $buy !== null && $buy !== '' ? Money::normalize($buy, 6) : null;

        $existing = $this->findDuplicate(
            provider: self::PROVIDER_HISTORICAL_IMPORT,
            rateAt: $rateAt,
            sell: $sellNorm,
            buy: $buyNorm,
            source: self::SOURCE_HISTORICAL_IMPORT,
            matchByDay: true,
        );

        if ($existing) {
            return $existing;
        }

        return $this->storeOfficialSell(
            rate: $sellNorm,
            rateBuy: $buyNorm,
            rateAt: $rateAt->toIso8601String(),
            source: self::SOURCE_HISTORICAL_IMPORT,
            provider: self::PROVIDER_HISTORICAL_IMPORT,
            payload: null,
            notes: $notes ?? 'Importación histórica',
            createdBy: $createdBy ?? Auth::id(),
        );
    }

    /**
     * @return array{rate: ExchangeRate, source_label: string}
     */
    public function latestOfficialSell(bool $trySync = false): array
    {
        if ($trySync) {
            try {
                $result = $this->updateOfficialFromProvider();

                return ['rate' => $result['rate'], 'source_label' => 'api'];
            } catch (RuntimeException) {
                // fallback below
            }
        }

        $rate = $this->queryLatest();

        if (! $rate) {
            throw new RuntimeException('No hay cotización disponible. Cargá una cotización manual o sincronizá DolarAPI.');
        }

        $label = match ($rate->source) {
            self::SOURCE_API => 'almacenamiento local (última API)',
            self::SOURCE_HISTORICAL_IMPORT => 'importación histórica',
            default => $rate->source,
        };

        return [
            'rate' => $rate,
            'source_label' => $label,
        ];
    }

    public function queryLatest(): ?ExchangeRate
    {
        $pair = $this->usdArsIds();
        if (! $pair) {
            return null;
        }

        return ExchangeRate::query()
            ->where('base_currency_id', $pair['usd'])
            ->where('quote_currency_id', $pair['ars'])
            ->where('rate_type', 'official_sell')
            ->orderByDesc('rate_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Cotización oficial de venta para una fecha: usa la del día o la última anterior
     * (fines de semana / feriados = última previa; no inventa valores).
     * No recalcula ni modifica cotizaciones congeladas en movimientos.
     */
    public function rateForDate(Carbon|string $date): ?ExchangeRate
    {
        $pair = $this->usdArsIds();
        if (! $pair) {
            return null;
        }

        $day = Carbon::parse($date)->endOfDay();

        return ExchangeRate::query()
            ->where('base_currency_id', $pair['usd'])
            ->where('quote_currency_id', $pair['ars'])
            ->where('rate_type', 'official_sell')
            ->where('rate_at', '<=', $day)
            ->orderByDesc('rate_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Vista previa de backfill ArgentinaDatos (sin escribir).
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   api_rows: int,
     *   to_import: int,
     *   already_present: int,
     *   sample: list<array{fecha: string, compra: ?string, venta: string}>,
     *   weekend_note: string
     * }
     */
    public function previewArgentinaDatosBackfill(Carbon|string $from, Carbon|string|null $to = null): array
    {
        $fromDay = Carbon::parse($from)->startOfDay();
        $toDay = $to ? Carbon::parse($to)->startOfDay() : now()->startOfDay();
        if ($toDay->lt($fromDay)) {
            throw new RuntimeException('El rango de backfill es inválido (hasta < desde).');
        }

        $rows = $this->historicalProvider->fetchOfficialHistory();
        $filtered = array_values(array_filter(
            $rows,
            fn (array $r) => $r['fecha'] >= $fromDay->toDateString() && $r['fecha'] <= $toDay->toDateString()
        ));

        $already = 0;
        $toImport = 0;
        foreach ($filtered as $row) {
            $sell = Money::normalize($row['venta'], 6);
            $buy = $row['compra'] !== null ? Money::normalize($row['compra'], 6) : null;
            $dup = $this->findDuplicate(
                provider: self::PROVIDER_ARGENTINADATOS,
                rateAt: Carbon::parse($row['fecha'])->startOfDay(),
                sell: $sell,
                buy: $buy,
                source: self::SOURCE_HISTORICAL_IMPORT,
                matchByDay: true,
            );
            if ($dup || $this->hasAnyOfficialOnDay($row['fecha'])) {
                $already++;
            } else {
                $toImport++;
            }
        }

        return [
            'from' => $fromDay->toDateString(),
            'to' => $toDay->toDateString(),
            'api_rows' => count($filtered),
            'to_import' => $toImport,
            'already_present' => $already,
            'sample' => array_slice($filtered, 0, 5),
            'weekend_note' => 'Fines de semana/feriados sin cotización API no se inventan; rateForDate usa la última previa.',
        ];
    }

    /**
     * Backfill idempotente desde ArgentinaDatos. No sobrescribe filas existentes
     * ni recalcula movimientos con FX congelado.
     *
     * @return array{imported: int, skipped: int, from: string, to: string}
     */
    public function backfillFromArgentinaDatos(Carbon|string $from, Carbon|string|null $to = null, ?int $createdBy = null): array
    {
        $preview = $this->previewArgentinaDatosBackfill($from, $to);
        $rows = $this->historicalProvider->fetchOfficialHistory();
        $filtered = array_values(array_filter(
            $rows,
            fn (array $r) => $r['fecha'] >= $preview['from'] && $r['fecha'] <= $preview['to']
        ));

        $imported = 0;
        $skipped = 0;

        foreach ($filtered as $row) {
            $sell = Money::normalize($row['venta'], 6);
            $buy = $row['compra'] !== null ? Money::normalize($row['compra'], 6) : null;
            $rateAt = Carbon::parse($row['fecha'])->startOfDay();

            if ($this->hasAnyOfficialOnDay($row['fecha'])) {
                $skipped++;
                continue;
            }

            $existing = $this->findDuplicate(
                provider: self::PROVIDER_ARGENTINADATOS,
                rateAt: $rateAt,
                sell: $sell,
                buy: $buy,
                source: self::SOURCE_HISTORICAL_IMPORT,
                matchByDay: true,
            );
            if ($existing) {
                $skipped++;
                continue;
            }

            $this->storeOfficialSell(
                rate: $sell,
                rateBuy: $buy,
                rateAt: $rateAt->toIso8601String(),
                source: self::SOURCE_HISTORICAL_IMPORT,
                provider: self::PROVIDER_ARGENTINADATOS,
                payload: ['casa' => 'oficial', 'fecha' => $row['fecha'], 'source' => 'argentinadatos'],
                notes: 'Backfill ArgentinaDatos oficial/BNA',
                createdBy: $createdBy ?? Auth::id(),
            );
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'from' => $preview['from'],
            'to' => $preview['to'],
        ];
    }

    private function hasAnyOfficialOnDay(string $date): bool
    {
        $pair = $this->usdArsIds();
        if (! $pair) {
            return false;
        }

        return ExchangeRate::query()
            ->where('base_currency_id', $pair['usd'])
            ->where('quote_currency_id', $pair['ars'])
            ->where('rate_type', 'official_sell')
            ->whereDate('rate_at', $date)
            ->exists();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, ExchangeRate>
     */
    public function history(?Carbon $from = null, ?Carbon $to = null, int $perPage = 30)
    {
        $query = ExchangeRate::query()
            ->with(['baseCurrency', 'quoteCurrency', 'creator'])
            ->where('rate_type', 'official_sell')
            ->orderByDesc('rate_at')
            ->orderByDesc('id');

        if ($from) {
            $query->where('rate_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $query->where('rate_at', '<=', $to->copy()->endOfDay());
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Puntos para gráfico (compra/venta) en el período.
     *
     * @return list<array{
     *   label: string,
     *   date: string,
     *   value: float,
     *   buy: ?float,
     *   sell: float,
     *   source: string,
     *   provider: ?string
     * }>
     */
    /**
     * Puntos del gráfico en el rango From/To exacto (sin truncar al inicio).
     * Si hay más de $maxPoints, subsamplea uniformemente preservando primero y último.
     * Los huecos (días sin cotización) son válidos: no se inventan puntos.
     *
     * @return list<array{
     *   label: string,
     *   date: string,
     *   value: float,
     *   buy: ?float,
     *   sell: float,
     *   source: string,
     *   provider: ?string
     * }>
     */
    public function chartPoints(?Carbon $from = null, ?Carbon $to = null, int $maxPoints = 500): array
    {
        $query = ExchangeRate::query()
            ->where('rate_type', 'official_sell')
            ->orderBy('rate_at')
            ->orderBy('id');

        if ($from) {
            $query->where('rate_at', '>=', $from->copy()->startOfDay());
        }
        if ($to) {
            $query->where('rate_at', '<=', $to->copy()->endOfDay());
        }

        $rows = $query->get(['rate_at', 'rate', 'rate_buy', 'source', 'provider']);
        $points = $rows->map(fn (ExchangeRate $r) => [
            'label' => $r->rate_at?->format('d/m') ?? '',
            'date' => $r->rate_at?->format('d/m/Y') ?? '',
            'value' => (float) $r->rate,
            'buy' => $r->rate_buy !== null ? (float) $r->rate_buy : null,
            'sell' => (float) $r->rate,
            'source' => (string) ($r->source ?? ''),
            'provider' => $r->provider,
        ])->values()->all();

        $count = count($points);
        if ($count <= $maxPoints || $maxPoints < 2) {
            return $points;
        }

        $sampled = [];
        $lastIndex = $count - 1;
        for ($i = 0; $i < $maxPoints; $i++) {
            $idx = (int) round($i * $lastIndex / ($maxPoints - 1));
            $sampled[$idx] = $points[$idx];
        }

        ksort($sampled);

        return array_values($sampled);
    }

    public function findDuplicate(
        string $provider,
        Carbon $rateAt,
        string $sell,
        ?string $buy,
        string $source,
        bool $matchByDay = false,
    ): ?ExchangeRate {
        $pair = $this->usdArsIds();
        if (! $pair) {
            return null;
        }

        $query = ExchangeRate::query()
            ->where('base_currency_id', $pair['usd'])
            ->where('quote_currency_id', $pair['ars'])
            ->where('rate_type', 'official_sell')
            ->where('provider', $provider)
            ->where('source', $source)
            ->where('rate', $sell);

        if ($buy === null) {
            $query->whereNull('rate_buy');
        } else {
            $query->where('rate_buy', $buy);
        }

        if ($matchByDay) {
            $query->whereDate('rate_at', $rateAt->toDateString());
        } else {
            $query->whereBetween('rate_at', [
                $rateAt->copy()->subSeconds(2),
                $rateAt->copy()->addSeconds(2),
            ]);
        }

        return $query->first();
    }

    private function storeOfficialSell(
        string $rate,
        ?string $rateBuy,
        string $rateAt,
        string $source,
        string $provider,
        ?array $payload,
        ?string $notes = null,
        ?int $createdBy = null,
    ): ExchangeRate {
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $ars = Currency::query()->where('code', 'ARS')->firstOrFail();

        return DB::transaction(function () use ($usd, $ars, $rate, $rateBuy, $rateAt, $source, $provider, $payload, $notes, $createdBy) {
            $model = ExchangeRate::query()->create([
                'base_currency_id' => $usd->id,
                'quote_currency_id' => $ars->id,
                'rate_type' => 'official_sell',
                'rate' => $rate,
                'rate_buy' => $rateBuy,
                'source' => $source,
                'provider' => $provider,
                'rate_at' => $rateAt,
                'created_by' => $createdBy ?? Auth::id(),
                'provider_payload' => $payload,
                'notes' => $notes,
            ]);

            $this->audit->log('exchange_rate_created', $model, null, $model->only([
                'rate', 'rate_buy', 'source', 'provider', 'rate_at', 'rate_type',
            ]), 'Cotización registrada');

            return $model;
        });
    }

    /**
     * @return array{usd: int, ars: int}|null
     */
    private function usdArsIds(): ?array
    {
        $usd = Currency::query()->where('code', 'USD')->first();
        $ars = Currency::query()->where('code', 'ARS')->first();

        if (! $usd || ! $ars) {
            return null;
        }

        return ['usd' => $usd->id, 'ars' => $ars->id];
    }
}
