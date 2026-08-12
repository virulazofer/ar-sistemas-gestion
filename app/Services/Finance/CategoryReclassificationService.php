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
     * Asegura estructura INGRESO → Servicios profesionales → {Abonos,Remotos,…} y Ventas.
     * Remotos nunca bajo Servicios (egreso).
     *
     * @return list<array{name: string, category_id: ?int, chart_account_id: ?int, action: string}>
     */
    public function ensureProfessionalIncomeMappings(bool $apply = false): array
    {
        $incomeProf = \App\Models\ChartAccount::query()->where('code', '4.2')->first();
        $report = [];

        $serviciosProf = Category::query()->whereRaw('LOWER(name) = ?', ['servicios profesionales'])->first();
        if (! $serviciosProf && $apply) {
            $serviciosProf = Category::query()->create([
                'name' => 'Servicios profesionales',
                'scope' => 'professional',
                'default_scope' => 'professional',
                'chart_account_id' => $incomeProf?->id,
                'is_active' => true,
                'sort_order' => 40,
                'excel_name' => 'Servicios profesionales',
            ]);
            $report[] = [
                'name' => 'Servicios profesionales',
                'category_id' => $serviciosProf->id,
                'chart_account_id' => $serviciosProf->chart_account_id,
                'action' => 'created_category',
            ];
        } elseif ($serviciosProf) {
            $action = $serviciosProf->chart_account_id === $incomeProf?->id ? 'ok' : 'map_category';
            if ($apply && $incomeProf && $action === 'map_category') {
                $old = $serviciosProf->only(['chart_account_id', 'scope']);
                $serviciosProf->update([
                    'chart_account_id' => $incomeProf->id,
                    'scope' => 'professional',
                ]);
                $this->audit->log('professional_income_mapped', $serviciosProf, $old, $serviciosProf->only(['chart_account_id', 'scope']), 'Servicios profesionales');
                $action = 'mapped';
            }
            $report[] = [
                'name' => 'Servicios profesionales',
                'category_id' => $serviciosProf->id,
                'chart_account_id' => $serviciosProf->chart_account_id,
                'action' => $action,
            ];
        } else {
            $report[] = ['name' => 'Servicios profesionales', 'category_id' => null, 'chart_account_id' => $incomeProf?->id, 'action' => 'missing'];
        }

        $incomeSubs = ['Abonos', 'Remotos', 'Reparaciones', 'Instalaciones'];
        foreach ($incomeSubs as $name) {
            $sub = null;
            if ($serviciosProf) {
                $sub = Subcategory::query()
                    ->where('category_id', $serviciosProf->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();
            }
            // Compat: categoría histórica suelta (ej. Remotos) o sub en otro padre.
            $legacyCat = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            $legacySub = Subcategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if (! $sub && ! $legacyCat && ! $legacySub) {
                $report[] = ['name' => $name, 'category_id' => $serviciosProf?->id, 'chart_account_id' => $incomeProf?->id, 'action' => 'missing'];

                continue;
            }

            if ($serviciosProf && ! $sub && $apply) {
                $sub = Subcategory::query()->create([
                    'category_id' => $serviciosProf->id,
                    'name' => $name,
                    'chart_account_id' => $incomeProf?->id,
                    'is_active' => true,
                    'sort_order' => 10,
                ]);
                $report[] = [
                    'name' => $name.' (sub)',
                    'category_id' => $serviciosProf->id,
                    'chart_account_id' => $sub->chart_account_id,
                    'action' => 'created_sub',
                ];
            } elseif ($sub) {
                $action = $sub->chart_account_id === $incomeProf?->id ? 'ok_sub' : 'map_sub';
                if ($apply && $incomeProf && $action === 'map_sub') {
                    $old = $sub->only(['chart_account_id']);
                    $sub->update(['chart_account_id' => $incomeProf->id]);
                    $this->audit->log('professional_income_sub_mapped', $sub, $old, $sub->only(['chart_account_id']), "Sub ingreso: {$name}");
                    $action = 'mapped_sub';
                }
                $report[] = [
                    'name' => $name.' (sub)',
                    'category_id' => $sub->category_id,
                    'chart_account_id' => $sub->chart_account_id,
                    'action' => $action,
                ];
            }

            if ($legacyCat && $serviciosProf && strcasecmp($legacyCat->name, 'Servicios profesionales') !== 0) {
                $action = 'legacy_category_to_sub';
                if ($apply && $sub) {
                    // Preview-only friendly: al aplicar, mueve movimientos de la cat suelta a sub canónica.
                    $moved = Movement::query()->where('category_id', $legacyCat->id)->update([
                        'category_id' => $serviciosProf->id,
                        'subcategory_id' => $sub->id,
                        'chart_account_id' => $incomeProf?->id ?? $legacyCat->chart_account_id,
                    ]);
                    $legacyCat->update(['is_active' => false, 'excel_name' => $legacyCat->excel_name ?: $legacyCat->name]);
                    $this->audit->log('legacy_income_category_folded', $legacyCat, null, [
                        'target_category_id' => $serviciosProf->id,
                        'target_subcategory_id' => $sub->id,
                        'moved' => $moved,
                    ], "Legado {$name} → Servicios profesionales/{$name}");
                    $action = 'folded_legacy_category';
                }
                $report[] = [
                    'name' => $name,
                    'category_id' => $legacyCat->id,
                    'chart_account_id' => $legacyCat->chart_account_id,
                    'action' => $action,
                    'note' => $name === 'Remotos' ? 'Remotos = INGRESO → Servicios profesionales → Remotos (no Servicios egreso).' : null,
                ];
            } elseif ($legacyCat && ! $serviciosProf) {
                $action = $legacyCat->chart_account_id === $incomeProf?->id ? 'ok' : 'map_category';
                if ($apply && $incomeProf && $action === 'map_category') {
                    $old = $legacyCat->only(['chart_account_id', 'scope']);
                    $legacyCat->update([
                        'chart_account_id' => $incomeProf->id,
                        'scope' => $legacyCat->scope === 'personal' ? 'professional' : $legacyCat->scope,
                    ]);
                    $this->audit->log('professional_income_mapped', $legacyCat, $old, $legacyCat->only(['chart_account_id', 'scope']), "Ingreso profesional: {$name}");
                    $action = 'mapped';
                }
                $report[] = [
                    'name' => $name,
                    'category_id' => $legacyCat->id,
                    'chart_account_id' => $legacyCat->chart_account_id,
                    'action' => $action,
                ];
            }
        }

        $ventas = Category::query()->whereRaw('LOWER(name) = ?', ['ventas'])->first();
        if (! $ventas && $apply) {
            $ventas = Category::query()->create([
                'name' => 'Ventas',
                'scope' => 'professional',
                'default_scope' => 'professional',
                'chart_account_id' => $incomeProf?->id,
                'is_active' => true,
                'sort_order' => 30,
                'excel_name' => 'Ventas',
            ]);
            $report[] = [
                'name' => 'Ventas',
                'category_id' => $ventas->id,
                'chart_account_id' => $ventas->chart_account_id,
                'action' => 'created_category',
                'note' => 'Clasificación económica profesional; circuito comercial intacto (sin duplicar ingreso).',
            ];
        } elseif ($ventas) {
            $action = $ventas->chart_account_id === $incomeProf?->id ? 'ok' : 'map_category';
            if ($apply && $incomeProf && $action === 'map_category') {
                $old = $ventas->only(['chart_account_id', 'scope']);
                $ventas->update(['chart_account_id' => $incomeProf->id, 'scope' => 'professional']);
                $this->audit->log('professional_income_mapped', $ventas, $old, $ventas->only(['chart_account_id', 'scope']), 'Ventas');
                $action = 'mapped';
            }
            $report[] = [
                'name' => 'Ventas',
                'category_id' => $ventas->id,
                'chart_account_id' => $ventas->chart_account_id,
                'action' => $action,
                'note' => 'Clasificación económica profesional; circuito comercial intacto (sin duplicar ingreso).',
            ];
        } else {
            $report[] = [
                'name' => 'Ventas',
                'category_id' => null,
                'chart_account_id' => $incomeProf?->id,
                'action' => 'missing',
                'note' => 'Clasificación económica profesional; circuito comercial intacto (sin duplicar ingreso).',
            ];
        }

        return $report;
    }
}
