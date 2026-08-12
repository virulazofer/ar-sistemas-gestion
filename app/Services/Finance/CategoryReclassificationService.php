<?php

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Reclasificación administrativa de categorías/subcategorías con preview + auditoría.
 * No bloquea por existencia de movimientos.
 */
class CategoryReclassificationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{movements: int, subcategories: int}
     */
    public function previewRenameCategory(Category $category, string $newName): array
    {
        return [
            'movements' => Movement::query()->where('category_id', $category->id)->count(),
            'subcategories' => $category->subcategories()->count(),
            'from' => $category->name,
            'to' => $newName,
        ];
    }

    public function renameCategory(Category $category, string $newName, ?string $excelAlias = null): Category
    {
        $old = $category->only(['name', 'excel_name']);
        $preview = $this->previewRenameCategory($category, $newName);

        $payload = ['name' => $newName];
        if ($excelAlias !== null) {
            $payload['excel_name'] = $excelAlias;
        } elseif (empty($category->excel_name) || $category->excel_name === $category->name) {
            // Conservar trazabilidad del nombre histórico.
            $payload['excel_name'] = $category->name;
        }

        $category->update($payload);
        $this->audit->log('category_renamed', $category, $old, array_merge($category->only(['name', 'excel_name']), ['preview' => $preview]), 'Categoría renombrada');

        return $category->fresh();
    }

    /**
     * @return array{movements: int}
     */
    public function previewRenameSubcategory(Subcategory $sub, string $newName): array
    {
        return [
            'movements' => Movement::query()->where('subcategory_id', $sub->id)->count(),
            'from' => $sub->name,
            'to' => $newName,
        ];
    }

    public function renameSubcategory(Subcategory $sub, string $newName): Subcategory
    {
        $old = $sub->only(['name']);
        $preview = $this->previewRenameSubcategory($sub, $newName);
        $sub->update(['name' => $newName]);
        $this->audit->log('subcategory_renamed', $sub, $old, array_merge($sub->only(['name']), ['preview' => $preview]), 'Subcategoría renombrada');

        return $sub->fresh();
    }

    /**
     * Fusiona categoría origen en destino (movimientos + opcionalmente subs).
     *
     * @return array{movements: int, subcategories: int, source: string, target: string}
     */
    public function previewMergeCategories(Category $source, Category $target): array
    {
        return [
            'movements' => Movement::query()->where('category_id', $source->id)->count(),
            'subcategories' => $source->subcategories()->count(),
            'source' => $source->name,
            'target' => $target->name,
        ];
    }

    /**
     * @return array{moved_movements: int, moved_subcategories: int}
     */
    public function mergeCategories(Category $source, Category $target, bool $deleteSource = true): array
    {
        if ($source->id === $target->id) {
            throw new \InvalidArgumentException('Origen y destino no pueden ser la misma categoría.');
        }

        return DB::transaction(function () use ($source, $target, $deleteSource) {
            $preview = $this->previewMergeCategories($source, $target);
            $movedSubs = 0;

            foreach ($source->subcategories as $sub) {
                $existing = Subcategory::query()
                    ->where('category_id', $target->id)
                    ->where('name', $sub->name)
                    ->first();
                if ($existing) {
                    Movement::query()->where('subcategory_id', $sub->id)->update(['subcategory_id' => $existing->id]);
                    $sub->delete();
                } else {
                    $sub->update(['category_id' => $target->id]);
                    $movedSubs++;
                }
            }

            $movedMovs = Movement::query()->where('category_id', $source->id)->update(['category_id' => $target->id]);
            $snapshot = $source->toArray();

            if ($deleteSource) {
                $source->delete();
            } else {
                $source->update(['is_active' => false]);
            }

            $result = ['moved_movements' => $movedMovs, 'moved_subcategories' => $movedSubs];
            $this->audit->log('categories_merged', $target, $preview, array_merge($result, ['source' => $snapshot]), 'Categorías fusionadas');

            return $result;
        });
    }

    /**
     * Convierte una categoría en subcategoría de otra (ej. Super → Alimentación/Supermercado).
     *
     * @return array{movements: int, source_category: string, target_subcategory: string}
     */
    public function previewConvertCategoryToSubcategory(Category $source, Subcategory $targetSub): array
    {
        return [
            'movements' => Movement::query()->where('category_id', $source->id)->count(),
            'source_category' => $source->name,
            'target_category' => $targetSub->category?->name,
            'target_subcategory' => $targetSub->name,
        ];
    }

    /**
     * @return array{moved_movements: int}
     */
    public function convertCategoryToSubcategory(Category $source, Subcategory $targetSub, bool $deactivateSource = true): array
    {
        return DB::transaction(function () use ($source, $targetSub, $deactivateSource) {
            $preview = $this->previewConvertCategoryToSubcategory($source, $targetSub);
            $moved = Movement::query()->where('category_id', $source->id)->update([
                'category_id' => $targetSub->category_id,
                'subcategory_id' => $targetSub->id,
                'chart_account_id' => $targetSub->chart_account_id ?? $targetSub->category?->chart_account_id,
            ]);

            // Conservar excel_name / alias histórico en la sub destino si aplica.
            if (empty($targetSub->category?->excel_name) && strcasecmp($source->name, 'Super') === 0) {
                // trazabilidad Super en excel_name de la categoría destino Alimentación
                $targetSub->category?->update([
                    'excel_name' => $targetSub->category->excel_name ?: $targetSub->category->name,
                ]);
            }

            if ($deactivateSource) {
                $source->update(['is_active' => false, 'excel_name' => $source->excel_name ?: $source->name]);
            }

            $this->audit->log('category_converted_to_subcategory', $targetSub, $preview, ['moved_movements' => $moved], 'Categoría convertida a subcategoría');

            return ['moved_movements' => $moved];
        });
    }

    /**
     * Normalización Super → Supermercado (preview + apply).
     *
     * @return array{found: bool, mode: string, preview: array<string, mixed>, applied?: array<string, mixed>}
     */
    public function normalizeSuperToSupermercado(bool $apply = false): array
    {
        $superCat = Category::query()->whereRaw('LOWER(name) = ?', ['super'])->first();
        $superSub = Subcategory::query()->whereRaw('LOWER(name) = ?', ['super'])->first();
        $supermercadoSub = Subcategory::query()->whereRaw('LOWER(name) = ?', ['supermercado'])->first();

        // También contar movimientos cuyo concepto/categoría histórica diga Super.
        $descCount = Movement::query()->posted()
            ->where(function ($q) {
                $q->where('description', 'like', '%Super%')
                    ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(name) = ?', ['super']));
            })->count();

        if ($superCat && $supermercadoSub) {
            $preview = $this->previewConvertCategoryToSubcategory($superCat, $supermercadoSub);
            $preview['description_hits'] = $descCount;
            $result = ['found' => true, 'mode' => 'category_to_sub', 'preview' => $preview];
            if ($apply) {
                $result['applied'] = $this->convertCategoryToSubcategory($superCat, $supermercadoSub);
                // Asegurar nombre visible Supermercado
                if (strcasecmp($supermercadoSub->name, 'Supermercado') !== 0) {
                    $this->renameSubcategory($supermercadoSub, 'Supermercado');
                }
            }

            return $result;
        }

        if ($superCat && ! $supermercadoSub) {
            $preview = $this->previewRenameCategory($superCat, 'Supermercado');
            $preview['description_hits'] = $descCount;
            $result = ['found' => true, 'mode' => 'rename_category', 'preview' => $preview];
            if ($apply) {
                $result['applied'] = ['category' => $this->renameCategory($superCat, 'Supermercado', 'Super')->only(['id', 'name', 'excel_name'])];
            }

            return $result;
        }

        if ($superSub) {
            $preview = $this->previewRenameSubcategory($superSub, 'Supermercado');
            $preview['description_hits'] = $descCount;
            $result = ['found' => true, 'mode' => 'rename_subcategory', 'preview' => $preview];
            if ($apply) {
                $result['applied'] = ['subcategory' => $this->renameSubcategory($superSub, 'Supermercado')->only(['id', 'name'])];
            }

            return $result;
        }

        return [
            'found' => false,
            'mode' => 'none',
            'preview' => ['movements' => 0, 'description_hits' => $descCount],
        ];
    }

    /**
     * Asegura categorías de ingresos profesionales confirmados mapeadas al plan 4.2.
     *
     * @return list<array{name: string, category_id: ?int, chart_account_id: ?int, action: string}>
     */
    public function ensureProfessionalIncomeMappings(bool $apply = false): array
    {
        $incomeProf = \App\Models\ChartAccount::query()->where('code', '4.2')->first();
        $names = ['Abonos', 'Reparaciones', 'Instalaciones', 'Remotos', 'Ventas'];
        $report = [];

        foreach ($names as $name) {
            $cat = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            $sub = Subcategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if (! $cat && ! $sub) {
                $report[] = ['name' => $name, 'category_id' => null, 'chart_account_id' => $incomeProf?->id, 'action' => 'missing'];

                continue;
            }

            if ($cat) {
                $action = $cat->chart_account_id === $incomeProf?->id ? 'ok' : 'map_category';
                if ($apply && $incomeProf && $action === 'map_category') {
                    $old = $cat->only(['chart_account_id', 'scope']);
                    $cat->update([
                        'chart_account_id' => $incomeProf->id,
                        'scope' => $cat->scope === 'personal' ? 'professional' : $cat->scope,
                    ]);
                    $this->audit->log('professional_income_mapped', $cat, $old, $cat->only(['chart_account_id', 'scope']), "Ingreso profesional: {$name}");
                    $action = 'mapped';
                }
                $report[] = [
                    'name' => $name,
                    'category_id' => $cat->id,
                    'chart_account_id' => $cat->chart_account_id,
                    'action' => $action,
                    'note' => $name === 'Ventas' ? 'Clasificación económica profesional; circuito comercial intacto (sin duplicar ingreso).' : null,
                ];
            }

            if ($sub && $sub->category_id !== ($cat->id ?? null)) {
                $action = $sub->chart_account_id === $incomeProf?->id ? 'ok_sub' : 'map_sub';
                if ($apply && $incomeProf && $action === 'map_sub') {
                    $old = $sub->only(['chart_account_id']);
                    $sub->update(['chart_account_id' => $incomeProf->id]);
                    $this->audit->log('professional_income_sub_mapped', $sub, $old, $sub->only(['chart_account_id']), "Sub ingreso profesional: {$name}");
                    $action = 'mapped_sub';
                }
                $report[] = [
                    'name' => $name.' (sub)',
                    'category_id' => $sub->category_id,
                    'chart_account_id' => $sub->chart_account_id,
                    'action' => $action,
                ];
            }
        }

        return $report;
    }
}
