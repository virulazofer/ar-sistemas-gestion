<?php

namespace App\Services\Finance;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UnclassifiedMovementsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChartAccountMappingService $mapping,
        private readonly ImputationRuleService $rules,
        private readonly OperationalClassificationService $operational,
    ) {}

    /**
     * Progreso operativo: NATURALEZA→CATEGORÍA→SUBCATEGORÍA.
     * Cat/sub correcta no exige cuenta contable para contar como clasificado.
     *
     * @return array{total: int, classified: int, pending: int, percent: float, missing_chart_optional: int}
     */
    public function progress(): array
    {
        return $this->operational->progress();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): Builder
    {
        $q = $this->operational->pendingQuery()
            ->with(['account', 'category', 'subcategory', 'chartAccount', 'client', 'supplier']);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $q->where(function (Builder $inner) use ($search) {
                $inner->where('description', 'like', "%{$search}%")
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('subcategory', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('account', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['scope'])) {
            $q->where('scope', $filters['scope']);
        }
        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }
        if (! empty($filters['category_id'])) {
            $q->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['financial_account_id'])) {
            $q->where('financial_account_id', (int) $filters['financial_account_id']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('movement_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('movement_date', '<=', $filters['to']);
        }

        $sort = (string) ($filters['sort'] ?? 'date_desc');
        match ($sort) {
            'date_asc' => $q->orderBy('movement_date')->orderBy('id'),
            'amount_desc' => $q->orderByDesc('amount_ars')->orderByDesc('id'),
            'amount_asc' => $q->orderBy('amount_ars')->orderBy('id'),
            'description' => $q->orderBy('description')->orderByDesc('id'),
            default => $q->orderByDesc('movement_date')->orderByDesc('id'),
        };

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Agrupa pendientes por patrón con nivel de confianza.
     *
     * @return list<array{
     *   key: string,
     *   label: string,
     *   pattern_type: string,
     *   pattern_value: string,
     *   count: int,
     *   confidence: string,
     *   sample_ids: list<int>,
     *   suggested_category_id: ?int,
     *   suggested_subcategory_id: ?int,
     *   suggested_chart_account_id: ?int
     * }>
     */
    public function groupByPattern(int $limit = 80): array
    {
        $rows = $this->operational->pendingQuery()
            ->with(['category', 'subcategory'])
            ->orderBy('id')
            ->get(['id', 'description', 'type', 'category_id', 'subcategory_id', 'client_id']);

        $groups = [];
        foreach ($rows as $m) {
            $desc = trim((string) $m->description);
            $normalized = $this->normalizeConcept($desc);
            $catName = $m->category?->name ?? 'Sin categoría';
            $key = $normalized !== ''
                ? 'desc:'.$normalized
                : 'cat:'.$catName.'|type:'.($m->type instanceof \BackedEnum ? $m->type->value : $m->type);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $normalized !== '' ? $desc : ($catName.' / '.($m->type instanceof \BackedEnum ? $m->type->label() : $m->type)),
                    'pattern_type' => $normalized !== '' ? 'description_contains' : 'category_name',
                    'pattern_value' => $normalized !== '' ? $this->extractToken($normalized) : $catName,
                    'count' => 0,
                    'sample_ids' => [],
                    'category_id' => $m->category_id,
                    'subcategory_id' => $m->subcategory_id,
                ];
            }
            $groups[$key]['count']++;
            if (count($groups[$key]['sample_ids']) < 20) {
                $groups[$key]['sample_ids'][] = $m->id;
            }
        }

        $out = [];
        foreach ($groups as $g) {
            $count = $g['count'];
            $confidence = $count >= 5 ? 'ALTA' : ($count >= 2 ? 'MEDIA' : 'BAJA');
            $suggestion = $this->suggestDestination($g['pattern_value'], $g['category_id'], $g['subcategory_id']);
            $out[] = [
                'key' => $g['key'],
                'label' => $g['label'],
                'pattern_type' => $g['pattern_type'],
                'pattern_value' => $g['pattern_value'],
                'count' => $count,
                'confidence' => $confidence,
                'sample_ids' => $g['sample_ids'],
                'suggested_category_id' => $suggestion['category_id'],
                'suggested_subcategory_id' => $suggestion['subcategory_id'],
                'suggested_chart_account_id' => $suggestion['chart_account_id'],
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($out, 0, $limit);
    }

    /**
     * @return array{updated: int}
     */
    public function classifyOne(Movement $movement, ?int $categoryId, ?int $subcategoryId, ?int $chartAccountId): array
    {
        $old = $movement->only(['category_id', 'subcategory_id', 'chart_account_id']);

        if ($chartAccountId === null && ($categoryId || $subcategoryId)) {
            $type = $movement->type instanceof \BackedEnum ? $movement->type->value : (string) $movement->getRawOriginal('type');
            $chartAccountId = $this->mapping->resolve($categoryId, $subcategoryId, $type)['chart_account_id']
                ?? $movement->chart_account_id;
        }

        $movement->update([
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'chart_account_id' => $chartAccountId,
        ]);

        $this->audit->log('movement_classified', $movement, $old, $movement->only(['category_id', 'subcategory_id', 'chart_account_id']), 'Clasificación operativa individual');

        return ['updated' => 1];
    }

    /**
     * @param  list<int>  $ids
     * @return array{would_affect: int, sample: list<array{id:int,description:?string}>}
     */
    public function previewBulk(array $ids, ?int $categoryId, ?int $subcategoryId, ?int $chartAccountId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        // Bulk operativo: permite reclasificar aunque ya tengan cuenta contable.
        $movements = Movement::query()->posted()->whereIn('id', $ids)->orderBy('id')->get();
        $sample = [];
        foreach ($movements->take(25) as $m) {
            $sample[] = [
                'id' => $m->id,
                'description' => $m->description,
                'from_category_id' => $m->category_id,
                'from_subcategory_id' => $m->subcategory_id,
            ];
        }

        return [
            'would_affect' => $movements->count(),
            'sample' => $sample,
            'destination' => [
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'chart_account_id' => $chartAccountId,
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{updated: int}
     */
    public function applyBulk(array $ids, ?int $categoryId, ?int $subcategoryId, ?int $chartAccountId): array
    {
        return DB::transaction(function () use ($ids, $categoryId, $subcategoryId, $chartAccountId) {
            $preview = $this->previewBulk($ids, $categoryId, $subcategoryId, $chartAccountId);
            $updated = 0;
            $movements = Movement::query()->posted()->whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($movements as $m) {
                $resolvedChart = $chartAccountId;
                if ($resolvedChart === null && ($categoryId || $subcategoryId)) {
                    $type = $m->type instanceof \BackedEnum ? $m->type->value : (string) $m->getRawOriginal('type');
                    $resolvedChart = $this->mapping->resolve($categoryId, $subcategoryId, $type)['chart_account_id'];
                }
                // Si no hay destino de plan, conservar el existente (no exigir mapeo redundante).
                if ($resolvedChart === null) {
                    $resolvedChart = $m->chart_account_id;
                }
                $m->update([
                    'category_id' => $categoryId ?? $m->category_id,
                    'subcategory_id' => $subcategoryId,
                    'chart_account_id' => $resolvedChart,
                ]);
                $updated++;
            }

            $this->audit->log('movements_bulk_classified', null, $preview, ['updated' => $updated], 'Clasificación masiva operativa');

            return ['updated' => $updated];
        });
    }

    private function normalizeConcept(string $description): string
    {
        $d = preg_replace('/\s+/', ' ', trim($description)) ?? '';
        // Quitar montos sueltos / fechas numéricas largas
        $d = preg_replace('/\b\d{5,}\b/', '', $d) ?? $d;

        return trim($d);
    }

    private function extractToken(string $normalized): string
    {
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $token = $parts[0] ?? $normalized;

        return mb_substr($token, 0, 80);
    }

    /**
     * @return array{category_id: ?int, subcategory_id: ?int, chart_account_id: ?int}
     */
    private function suggestDestination(string $patternValue, ?int $categoryId, ?int $subcategoryId): array
    {
        $ruleMatch = $this->rules->match($patternValue, null, null);
        if ($ruleMatch) {
            return [
                'category_id' => $ruleMatch['category_id'] ?? $categoryId,
                'subcategory_id' => $ruleMatch['subcategory_id'] ?? $subcategoryId,
                'chart_account_id' => $ruleMatch['chart_account_id'],
            ];
        }

        $resolved = $this->mapping->resolve($categoryId, $subcategoryId, null);

        return [
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'chart_account_id' => $resolved['chart_account_id'],
        ];
    }
}
