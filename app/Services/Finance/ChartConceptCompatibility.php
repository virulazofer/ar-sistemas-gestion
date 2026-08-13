<?php

namespace App\Services\Finance;

use App\Enums\ChartAccountType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Subcategory;

/**
 * Puente progresivo categoría/sub ↔ hoja del plan (INGRESOS/EGRESOS).
 * No destruye tablas; dual-read/dual-write mientras dure la transición.
 */
class ChartConceptCompatibility
{
    /**
     * Mapeo nombre canónico → código del árbol §2.
     *
     * @return array<string, string>
     */
    public function approvedNameToCode(): array
    {
        return [
            'supermercado' => '5.1.1',
            'super' => '5.1.1',
            'comidas' => '5.1.2',
            'comida' => '5.1.2',
            'carnicería' => '5.1.3',
            'carniceria' => '5.1.3',
            'alimentación' => '5.1',
            'alimentacion' => '5.1',
            'electricidad' => '5.2.1',
            'gas' => '5.2.2',
            'internet' => '5.2.3',
            'telefonía' => '5.2.4',
            'telefonia' => '5.2.4',
            'suscripciones' => '5.2.5',
            'streaming' => '5.2.5',
            'servicios' => '5.2',
            'combustible' => '5.3.1',
            'peajes' => '5.3.2',
            'estacionamiento' => '5.3.3',
            'seguro' => '5.3.4',
            'mantenimiento' => '5.3.5',
            'mantenimiento / reparaciones' => '5.3.5',
            'lavado/limpieza' => '5.3.6',
            'lavado / limpieza' => '5.3.6',
            'automotor' => '5.3',
            'auto' => '5.3',
            'miranda' => '5.4.1',
            'gastos familiares' => '5.4',
            'muebles y útiles' => '5.5',
            'muebles y utiles' => '5.5',
            'myu' => '5.5',
            'impuestos y tasas' => '5.6',
            'otros egresos' => '5.7',
            'ventas' => '4.1',
            'equipos' => '4.1.1',
            'componentes' => '4.1.2',
            'abonos' => '4.2.1',
            'reparaciones' => '4.2.2',
            'remotos' => '4.2.3',
            'instalaciones' => '4.2.4',
            'consultoría' => '4.2.5',
            'consultoria' => '4.2.5',
            'servicios profesionales' => '4.2',
            'intereses' => '4.3.1',
            'rendimientos' => '4.3.2',
            'financieros' => '4.3',
            'ingresos financieros' => '4.3',
        ];
    }

    public function chartForName(?string $name): ?ChartAccount
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $key = mb_strtolower(trim($name));
        $code = $this->approvedNameToCode()[$key] ?? null;
        if (! $code) {
            return ChartAccount::query()
                ->whereRaw('LOWER(name) = ?', [$key])
                ->whereIn('type', [ChartAccountType::Income->value, ChartAccountType::Expense->value])
                ->orderByRaw('LENGTH(code) DESC')
                ->first();
        }

        return ChartAccount::query()->where('code', $code)->first();
    }

    /**
     * Resuelve concepto económico: prioriza chart_account_id; complementa cat/sub.
     *
     * @return array{chart_account_id:?int,category_id:?int,subcategory_id:?int}
     */
    public function resolveFromInput(?int $chartAccountId, ?int $categoryId, ?int $subcategoryId): array
    {
        if ($chartAccountId) {
            $chart = ChartAccount::query()->find($chartAccountId);
            $bridge = $this->ensureOperationalBridge($chart);

            return [
                'chart_account_id' => $chartAccountId,
                'category_id' => $bridge['category_id'] ?? $categoryId,
                'subcategory_id' => $bridge['subcategory_id'] ?? $subcategoryId,
            ];
        }

        $sub = $subcategoryId ? Subcategory::query()->with('category')->find($subcategoryId) : null;
        $cat = $categoryId ? Category::query()->find($categoryId) : $sub?->category;

        $chart = null;
        if ($sub?->chart_account_id) {
            $chart = ChartAccount::query()->find($sub->chart_account_id);
        }
        if (! $chart && $cat?->chart_account_id) {
            $chart = ChartAccount::query()->find($cat->chart_account_id);
        }
        if (! $chart && $sub) {
            $chart = $this->chartForName($sub->name);
        }
        if (! $chart && $cat) {
            $chart = $this->chartForName($cat->name);
        }

        return [
            'chart_account_id' => $chart?->id,
            'category_id' => $cat?->id ?? $categoryId,
            'subcategory_id' => $sub?->id ?? $subcategoryId,
        ];
    }

    /**
     * Asegura categoría/sub espejo (sin borrar históricas) para dual-read.
     *
     * @return array{category_id:?int,subcategory_id:?int}
     */
    public function ensureOperationalBridge(?ChartAccount $chart): array
    {
        if (! $chart || ! in_array($chart->type, [ChartAccountType::Income, ChartAccountType::Expense], true)) {
            return ['category_id' => null, 'subcategory_id' => null];
        }

        // Preferir hoja: sub = hoja, cat = padre inmediato bajo raíz 4/5
        $parent = $chart->parent;
        $isUnderRoot = $parent && in_array($parent->code, ['4', '5'], true);

        if ($isUnderRoot || $chart->parent_id === null) {
            $cat = Category::query()->firstOrCreate(
                ['name' => $chart->name],
                [
                    'scope' => $chart->type === ChartAccountType::Income ? 'professional' : 'both',
                    'chart_account_id' => $chart->id,
                    'is_active' => true,
                    'sort_order' => $chart->sort_order,
                    'excel_name' => $chart->name,
                    'default_scope' => $chart->suggested_scope,
                ]
            );
            if (! $cat->chart_account_id) {
                $cat->update(['chart_account_id' => $chart->id]);
            }

            return ['category_id' => $cat->id, 'subcategory_id' => null];
        }

        $catChart = $parent;
        while ($catChart?->parent && ! in_array($catChart->parent->code, ['4', '5'], true)) {
            $catChart = $catChart->parent;
        }

        $cat = Category::query()->firstOrCreate(
            ['name' => $catChart->name],
            [
                'scope' => $chart->type === ChartAccountType::Income ? 'professional' : 'both',
                'chart_account_id' => $catChart->id,
                'is_active' => true,
                'sort_order' => $catChart->sort_order,
                'excel_name' => $catChart->name,
                'default_scope' => $catChart->suggested_scope,
            ]
        );
        if (! $cat->chart_account_id) {
            $cat->update(['chart_account_id' => $catChart->id]);
        }

        $sub = Subcategory::query()->firstOrCreate(
            ['category_id' => $cat->id, 'name' => $chart->name],
            [
                'chart_account_id' => $chart->id,
                'is_active' => true,
                'sort_order' => $chart->sort_order,
            ]
        );
        if (! $sub->chart_account_id) {
            $sub->update(['chart_account_id' => $chart->id]);
        }

        return ['category_id' => $cat->id, 'subcategory_id' => $sub->id];
    }

    /**
     * Remapea cat/sub existentes hacia códigos del árbol nuevo (solo reglas; no toca movimientos).
     *
     * @return array{categories:int,subcategories:int,unmapped:list<string>}
     */
    public function remapMasters(bool $write = true): array
    {
        $catN = 0;
        $subN = 0;
        $unmapped = [];

        foreach (Category::query()->orderBy('id')->get() as $cat) {
            $chart = $this->chartForName($cat->name) ?? $this->chartForName($cat->excel_name);
            if (! $chart) {
                $unmapped[] = 'cat:'.$cat->name;

                continue;
            }
            if ($write && (int) $cat->chart_account_id !== (int) $chart->id) {
                $cat->update(['chart_account_id' => $chart->id]);
                $catN++;
            } elseif ($write && ! $cat->chart_account_id) {
                $cat->update(['chart_account_id' => $chart->id]);
                $catN++;
            }
        }

        foreach (Subcategory::query()->orderBy('id')->get() as $sub) {
            $chart = $this->chartForName($sub->name);
            if (! $chart) {
                $unmapped[] = 'sub:'.$sub->name;

                continue;
            }
            if ($write && (int) $sub->chart_account_id !== (int) $chart->id) {
                $sub->update(['chart_account_id' => $chart->id]);
                $subN++;
            } elseif ($write && ! $sub->chart_account_id) {
                $sub->update(['chart_account_id' => $chart->id]);
                $subN++;
            }
        }

        return ['categories' => $catN, 'subcategories' => $subN, 'unmapped' => $unmapped];
    }
}
