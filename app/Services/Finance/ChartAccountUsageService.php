<?php

namespace App\Services\Finance;

use App\Models\ChartAccount;
use App\Models\ChartAccountUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ChartAccountUsageService
{
    public function remember(?int $chartAccountId, ?int $userId = null): void
    {
        $chartAccountId = (int) ($chartAccountId ?? 0);
        $userId = $userId ?? Auth::id();
        if ($chartAccountId <= 0 || ! $userId) {
            return;
        }

        $usage = ChartAccountUsage::query()->firstOrNew([
            'user_id' => $userId,
            'chart_account_id' => $chartAccountId,
        ]);
        $usage->use_count = ((int) $usage->use_count) + 1;
        $usage->last_used_at = now();
        $usage->save();
    }

    /**
     * @return array{recent: list<array<string,mixed>>, frequent: list<array<string,mixed>>}
     */
    public function forUser(?int $userId = null, int $recentLimit = 8, int $frequentLimit = 8): array
    {
        $userId = $userId ?? Auth::id();
        if (! $userId) {
            return ['recent' => [], 'frequent' => []];
        }

        $map = function (Collection $usages): array {
            return $usages->map(function (ChartAccountUsage $u) {
                $c = $u->chartAccount;
                if (! $c) {
                    return null;
                }

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'type' => $c->type instanceof \BackedEnum ? $c->type->value : (string) $c->type,
                    'path' => $c->pathLabel(),
                    'suggested_scope' => $c->suggested_scope,
                    'group' => 'usage',
                ];
            })->filter()->values()->all();
        };

        $recent = ChartAccountUsage::query()
            ->with('chartAccount')
            ->where('user_id', $userId)
            ->whereHas('chartAccount', fn ($q) => $q->where('is_active', true)->whereNotNull('parent_id'))
            ->orderByDesc('last_used_at')
            ->limit($recentLimit)
            ->get();

        $frequent = ChartAccountUsage::query()
            ->with('chartAccount')
            ->where('user_id', $userId)
            ->whereHas('chartAccount', fn ($q) => $q->where('is_active', true)->whereNotNull('parent_id'))
            ->orderByDesc('use_count')
            ->orderByDesc('last_used_at')
            ->limit($frequentLimit)
            ->get();

        return [
            'recent' => $map($recent),
            'frequent' => $map($frequent),
        ];
    }
}
