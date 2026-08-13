<?php

namespace App\Services\Finance;

use App\Models\ImputationRule;
use App\Models\Movement;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImputationRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, ImputationRule>
     */
    public function activeOrdered(): Collection
    {
        return ImputationRule::query()
            ->with(['targetCategory', 'targetSubcategory', 'targetChartAccount', 'creator'])
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{category_id: ?int, subcategory_id: ?int, chart_account_id: ?int, source: string, rule_id: ?int}|null
     */
    public function match(?string $description, ?string $movementType, ?string $categoryName = null): ?array
    {
        foreach ($this->activeOrdered() as $rule) {
            if (! $this->ruleMatches($rule, $description, $movementType, $categoryName)) {
                continue;
            }

            return [
                'category_id' => $rule->target_category_id ? (int) $rule->target_category_id : null,
                'subcategory_id' => $rule->target_subcategory_id ? (int) $rule->target_subcategory_id : null,
                'chart_account_id' => $rule->target_chart_account_id ? (int) $rule->target_chart_account_id : null,
                'source' => 'imputation_rule',
                'rule_id' => (int) $rule->id,
            ];
        }

        return null;
    }

    public function ruleMatches(ImputationRule $rule, ?string $description, ?string $movementType, ?string $categoryName = null): bool
    {
        $needle = mb_strtolower(trim((string) $rule->condition_value));
        $hayDescription = mb_strtolower(trim((string) $description));
        $hayType = mb_strtolower(trim((string) $movementType));
        $hayCat = mb_strtolower(trim((string) $categoryName));

        return match ($rule->condition_type) {
            ImputationRule::TYPE_DESCRIPTION_CONTAINS => $needle !== '' && str_contains($hayDescription, $needle),
            ImputationRule::TYPE_EXACT_DESCRIPTION => $needle !== '' && $hayDescription === $needle,
            ImputationRule::TYPE_MOVEMENT_TYPE => $needle !== '' && $hayType === $needle,
            ImputationRule::TYPE_CATEGORY_NAME => $needle !== '' && $hayCat === $needle,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ImputationRule
    {
        $rule = ImputationRule::query()->create([
            'name' => $data['name'] ?? null,
            'condition_type' => $data['condition_type'],
            'condition_value' => trim((string) $data['condition_value']),
            'target_category_id' => $data['target_category_id'] ?? null,
            'target_subcategory_id' => $data['target_subcategory_id'] ?? null,
            'target_chart_account_id' => $data['target_chart_account_id'] ?? null,
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'allow_manual_override' => (bool) ($data['allow_manual_override'] ?? true),
            'created_by' => Auth::id(),
        ]);

        $rule->cached_match_count = $this->countMatches($rule);
        $rule->save();

        $this->audit->log('imputation_rule_created', $rule, null, $rule->toArray(), 'Regla de imputación creada');

        return $rule->fresh(['targetCategory', 'targetSubcategory', 'targetChartAccount', 'creator']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ImputationRule $rule, array $data): ImputationRule
    {
        $old = $rule->toArray();
        $rule->fill([
            'name' => $data['name'] ?? $rule->name,
            'condition_type' => $data['condition_type'] ?? $rule->condition_type,
            'condition_value' => array_key_exists('condition_value', $data) ? trim((string) $data['condition_value']) : $rule->condition_value,
            'target_category_id' => array_key_exists('target_category_id', $data) ? $data['target_category_id'] : $rule->target_category_id,
            'target_subcategory_id' => array_key_exists('target_subcategory_id', $data) ? $data['target_subcategory_id'] : $rule->target_subcategory_id,
            'target_chart_account_id' => array_key_exists('target_chart_account_id', $data) ? $data['target_chart_account_id'] : $rule->target_chart_account_id,
            'priority' => array_key_exists('priority', $data) ? (int) $data['priority'] : $rule->priority,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $rule->is_active,
            'allow_manual_override' => array_key_exists('allow_manual_override', $data) ? (bool) $data['allow_manual_override'] : $rule->allow_manual_override,
        ]);
        $rule->cached_match_count = $this->countMatches($rule);
        $rule->save();

        $this->audit->log('imputation_rule_updated', $rule, $old, $rule->toArray(), 'Regla de imputación actualizada');

        return $rule->fresh(['targetCategory', 'targetSubcategory', 'targetChartAccount', 'creator']);
    }

    public function countMatches(ImputationRule $rule): int
    {
        $q = Movement::query()->posted()->whereIn('type', ['income', 'expense']);

        return match ($rule->condition_type) {
            ImputationRule::TYPE_DESCRIPTION_CONTAINS => $q->where('description', 'like', '%'.$rule->condition_value.'%')->count(),
            ImputationRule::TYPE_EXACT_DESCRIPTION => $q->where('description', $rule->condition_value)->count(),
            ImputationRule::TYPE_MOVEMENT_TYPE => $q->where('type', $rule->condition_value)->count(),
            ImputationRule::TYPE_CATEGORY_NAME => $q->whereHas('category', fn ($c) => $c->where('name', $rule->condition_value))->count(),
            default => 0,
        };
    }

    /**
     * Preview de aplicar una regla a movimientos (o a IDs dados).
     * Por defecto no sobrescribe categoría/sub/plan ya confirmados.
     *
     * @param  list<int>|null  $movementIds
     * @return array{
     *   matched: int,
     *   manual: int,
     *   would_change: int,
     *   intact: int,
     *   would_affect: int,
     *   overwrite_manual: bool,
     *   sample: list<array{id: int, date: string, description: ?string, status: string}>
     * }
     */
    public function previewApply(
        ImputationRule $rule,
        ?array $movementIds = null,
        bool $onlyUnassigned = true,
        bool $overwriteManual = false,
    ): array {
        $query = Movement::query()->posted()->whereIn('type', ['income', 'expense']);
        if ($movementIds !== null) {
            $query->whereIn('id', $movementIds);
        }

        $candidates = $query->with('category')->orderBy('id')->get();
        $matched = 0;
        $manual = 0;
        $wouldChange = 0;
        $intact = 0;
        $sample = [];

        foreach ($candidates as $m) {
            $type = $m->type instanceof \BackedEnum ? $m->type->value : (string) $m->getRawOriginal('type');
            if (! $this->ruleMatches($rule, $m->description, $type, $m->category?->name)) {
                continue;
            }

            $matched++;
            $payload = $this->buildApplyPayload($rule, $m, $overwriteManual, $onlyUnassigned);
            if ($payload['status'] === 'manual') {
                $manual++;
            } elseif ($payload['status'] === 'would_change') {
                $wouldChange++;
            } else {
                $intact++;
            }

            if (count($sample) < 25) {
                $sample[] = [
                    'id' => $m->id,
                    'date' => $m->movement_date?->toDateString() ?? '',
                    'description' => $m->description,
                    'status' => $payload['status'],
                ];
            }
        }

        return [
            'matched' => $matched,
            'manual' => $manual,
            'would_change' => $wouldChange,
            'intact' => $intact,
            'would_affect' => $wouldChange,
            'overwrite_manual' => $overwriteManual,
            'sample' => $sample,
        ];
    }

    /**
     * @param  list<int>|null  $movementIds
     * @return array{updated: int, manual_skipped: int, intact: int}
     */
    public function apply(
        ImputationRule $rule,
        ?array $movementIds = null,
        bool $onlyUnassigned = true,
        bool $overwriteManual = false,
    ): array {
        return DB::transaction(function () use ($rule, $movementIds, $onlyUnassigned, $overwriteManual) {
            $preview = $this->previewApply($rule, $movementIds, $onlyUnassigned, $overwriteManual);
            $query = Movement::query()->posted()->whereIn('type', ['income', 'expense'])->lockForUpdate();
            if ($movementIds !== null) {
                $query->whereIn('id', $movementIds);
            }

            $updated = 0;
            $manualSkipped = 0;
            $intact = 0;
            foreach ($query->with('category')->get() as $m) {
                $type = $m->type instanceof \BackedEnum ? $m->type->value : (string) $m->getRawOriginal('type');
                if (! $this->ruleMatches($rule, $m->description, $type, $m->category?->name)) {
                    continue;
                }

                $built = $this->buildApplyPayload($rule, $m, $overwriteManual, $onlyUnassigned);
                if ($built['status'] === 'manual') {
                    $manualSkipped++;

                    continue;
                }
                if ($built['status'] !== 'would_change' || $built['payload'] === []) {
                    $intact++;

                    continue;
                }

                $m->update($built['payload']);
                $updated++;
            }

            $rule->cached_match_count = $this->countMatches($rule);
            $rule->save();

            $this->audit->log('imputation_rule_applied', $rule, $preview, [
                'updated' => $updated,
                'manual_skipped' => $manualSkipped,
                'intact' => $intact,
                'overwrite_manual' => $overwriteManual,
            ], 'Regla de clasificación automática aplicada');

            return [
                'updated' => $updated,
                'manual_skipped' => $manualSkipped,
                'intact' => $intact,
            ];
        });
    }

    /**
     * @return array{status: string, payload: array<string, mixed>}
     */
    private function buildApplyPayload(
        ImputationRule $rule,
        Movement $movement,
        bool $overwriteManual,
        bool $onlyUnassigned,
    ): array {
        $payload = [];
        $blockedManual = false;

        if ($rule->target_category_id) {
            $current = $movement->category_id ? (int) $movement->category_id : null;
            $target = (int) $rule->target_category_id;
            if ($current === null) {
                $payload['category_id'] = $target;
            } elseif ($current !== $target) {
                if ($overwriteManual) {
                    $payload['category_id'] = $target;
                } else {
                    $blockedManual = true;
                }
            }
        }

        if ($rule->target_subcategory_id) {
            $current = $movement->subcategory_id ? (int) $movement->subcategory_id : null;
            $target = (int) $rule->target_subcategory_id;
            if ($current === null) {
                $payload['subcategory_id'] = $target;
            } elseif ($current !== $target) {
                if ($overwriteManual) {
                    $payload['subcategory_id'] = $target;
                } else {
                    $blockedManual = true;
                }
            }
        }

        if ($rule->target_chart_account_id) {
            $current = $movement->chart_account_id ? (int) $movement->chart_account_id : null;
            $target = (int) $rule->target_chart_account_id;
            if ($current === null) {
                $payload['chart_account_id'] = $target;
            } elseif ($current !== $target) {
                if ($overwriteManual) {
                    $payload['chart_account_id'] = $target;
                } else {
                    $blockedManual = true;
                }
            }
        } elseif ($onlyUnassigned && $movement->chart_account_id && $payload === [] && ! $blockedManual) {
            // Compat: filtro histórico "solo sin plan" cuando la regla no trae chart.
            return ['status' => 'intact', 'payload' => []];
        }

        if ($blockedManual && $payload === []) {
            return ['status' => 'manual', 'payload' => []];
        }

        if ($payload === []) {
            return ['status' => 'intact', 'payload' => []];
        }

        if ($blockedManual && ! $overwriteManual) {
            // Hay campos fillables (null) pero también hay conflicto manual: no tocar.
            return ['status' => 'manual', 'payload' => []];
        }

        return ['status' => 'would_change', 'payload' => $payload];
    }

    /**
     * Migra defaults tipo→plan a reglas de imputación si aún no existen.
     *
     * @param  array{income: ?int, expense: ?int}  $defaults
     */
    public function syncTypeDefaultRules(array $defaults): void
    {
        foreach (['income', 'expense'] as $type) {
            $chartId = $defaults[$type] ?? null;
            $existing = ImputationRule::query()
                ->where('condition_type', ImputationRule::TYPE_MOVEMENT_TYPE)
                ->where('condition_value', $type)
                ->first();

            if (! $chartId) {
                if ($existing) {
                    $existing->update(['is_active' => false]);
                }

                continue;
            }

            if ($existing) {
                $existing->update([
                    'target_chart_account_id' => $chartId,
                    'is_active' => true,
                    'priority' => 900,
                    'name' => 'Tipo '.$type.' → plan',
                ]);
            } else {
                $this->create([
                    'name' => 'Tipo '.$type.' → plan',
                    'condition_type' => ImputationRule::TYPE_MOVEMENT_TYPE,
                    'condition_value' => $type,
                    'target_chart_account_id' => $chartId,
                    'priority' => 900,
                    'is_active' => true,
                    'allow_manual_override' => true,
                ]);
            }
        }
    }
}
