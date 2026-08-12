<?php

namespace App\Services\Finance;

use App\Enums\MovementType;
use App\Models\Category;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\Exports\ExportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dry-run de reclasificación estructural 11F-8.
 * Nunca aplica cambios masivos desde este servicio (apply queda en CategoryReclassificationService).
 */
class StructuralReclassificationPlanner
{
    /** @var list<string> */
    private const AUTO_FUEL = ['ypf', 'shell', 'axion', 'nafta', 'combustible', 'gasoil', 'diesel', 'puma energy', 'oil estación'];

    /** @var list<string> */
    private const AUTO_INSURANCE = ['seguro auto', 'seguro del auto', 'la caja', 'sancor', 'federacion patronal', 'seguro vehiculo', 'seguro vehículo', 'mapfre auto'];

    /** @var list<string> */
    private const AUTO_PATENTE = ['patente', 'vtv', 'verificacion tecnica', 'verificación técnica'];

    /** @var list<string> */
    private const AUTO_PEAJE = ['peaje', 'autopistas', 'ausa', 'aubasa', 'telepase', 'telepase'];

    /** @var list<string> */
    private const AUTO_PARKING = ['estacionamiento', 'parking', 'cochera'];

    /** @var list<string> */
    private const AUTO_MAINT = [
        'taller', 'service auto', 'aceite', 'acdelco', 'cubiertas', 'neumaticos', 'neumáticos',
        'mecanico', 'mecánico', 'lubricentro', 'filtro', 'filtros', 'distribucion', 'distribución',
        'pata de motor', 'refrigerante', 'limpiavidrios', 'bujia', 'bujía', 'pastilla', 'frenos',
    ];

    public function __construct(
        private readonly ApprovedTaxonomyService $taxonomy,
        private readonly OperationalClassificationService $operational,
        private readonly ExportService $exports,
    ) {}

    /**
     * @return array{
     *   taxonomy_preview: array<string, mixed>,
     *   groups: list<array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   auto_breakdown: array<string, int>,
     *   ambiguous: list<array<string, mixed>>,
     *   applied: false
     * }
     */
    public function dryRun(bool $ensureTaxonomyWrite = false): array
    {
        $taxonomy = $this->taxonomy->ensureCanonical(write: $ensureTaxonomyWrite);

        $groups = [
            $this->planSuper(),
            $this->planComida(),
            $this->planMiranda(),
            $this->planMyu(),
            $this->planRemotos(),
            $this->planAuto(),
        ];

        $autoGroup = collect($groups)->firstWhere('grupo', 'Auto') ?? [];
        $autoBreakdown = $autoGroup['subcategory_breakdown'] ?? [];

        $resolvable = 0;
        foreach ($groups as $g) {
            $resolvable += (int) ($g['propuesta_alta_confianza'] ?? 0);
        }

        $pendingBefore = $this->operational->countPending();
        // Estimación: pendientes operativos actuales menos los que ganarían categoría con propuestas ALTA.
        // También contamos movimientos que YA tienen categoría incorrecta (p.ej. Super) como "encontrados".
        $foundTagged = collect($groups)->sum(fn ($g) => (int) ($g['encontrados'] ?? 0));

        $ambiguous = $this->collectAmbiguous($groups);
        $progress = $this->operational->progress();

        return [
            'taxonomy_preview' => $taxonomy,
            'groups' => $groups,
            'summary' => [
                'pendientes_antes' => $pendingBefore,
                'encontrados_grupos' => $foundTagged,
                'resueltos_potencialmente' => $resolvable,
                'pendientes_reales_despues_estimado' => max(0, $pendingBefore - $this->estimatePendingResolved($groups)),
                'missing_chart_optional' => $progress['missing_chart_optional'],
                'classified_operativos' => $progress['classified'],
                'total_income_expense' => $progress['total'],
            ],
            'auto_breakdown' => $autoBreakdown,
            'ambiguous' => $ambiguous,
            'applied' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function exportAmbiguousCsv(array $report, string $filename = '11f8-pendientes-ambiguos.csv'): StreamedResponse
    {
        $headers = ['id', 'fecha', 'descripcion', 'importe_ars', 'ambito', 'tipo', 'categoria_actual', 'subcategoria_actual', 'grupo', 'motivo'];
        $rows = [];
        foreach ($report['ambiguous'] ?? [] as $row) {
            $rows[] = [
                $row['id'] ?? '',
                $row['date'] ?? '',
                $row['description'] ?? '',
                $row['amount_ars'] ?? '',
                $row['scope'] ?? '',
                $row['type'] ?? '',
                $row['category'] ?? '',
                $row['subcategory'] ?? '',
                $row['grupo'] ?? '',
                $row['motivo'] ?? '',
            ];
        }

        return $this->exports->toCsv($filename, $headers, $rows);
    }

    /**
     * Guarda CSV/XLSX en storage local (para dry-run staging/local).
     *
     * @param  array<string, mixed>  $report
     * @return array{csv: string, xlsx: string, count: int}
     */
    public function writeAmbiguousExports(array $report, string $dir = 'exports/11f8'): array
    {
        Storage::disk('local')->makeDirectory($dir);
        $headers = ['id', 'fecha', 'descripcion', 'importe_ars', 'ambito', 'tipo', 'categoria_actual', 'subcategoria_actual', 'grupo', 'motivo'];
        $rows = [];
        foreach ($report['ambiguous'] ?? [] as $row) {
            $rows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['date'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['amount_ars'] ?? ''),
                (string) ($row['scope'] ?? ''),
                (string) ($row['type'] ?? ''),
                (string) ($row['category'] ?? ''),
                (string) ($row['subcategory'] ?? ''),
                (string) ($row['grupo'] ?? ''),
                (string) ($row['motivo'] ?? ''),
            ];
        }

        $csvRel = $dir.'/pendientes-ambiguos.csv';
        $xlsxRel = $dir.'/pendientes-ambiguos.xlsx';
        $csvPath = Storage::disk('local')->path($csvRel);
        $fh = fopen($csvPath, 'w');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo escribir CSV de ambiguos.');
        }
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, $headers, ';');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ';');
        }
        fclose($fh);

        // Reutilizar ExportService vía buffer XLSX en disco.
        $xlsxPath = Storage::disk('local')->path($xlsxRel);
        $this->writeXlsxFile($xlsxPath, $headers, $rows);

        return ['csv' => $csvRel, 'xlsx' => $xlsxRel, 'count' => count($rows)];
    }

    /**
     * @return array<string, mixed>
     */
    private function planSuper(): array
    {
        $targetCat = $this->taxonomy->findCategory('Alimentación');
        $targetSub = $this->taxonomy->findSubcategory('Alimentación', 'Supermercado');
        $sourceCats = Category::query()->whereRaw('LOWER(name) = ?', ['super'])->get();
        $sourceSubs = Subcategory::query()->whereRaw('LOWER(name) = ?', ['super'])->get();

        $movements = $this->movementsMatchingNames(['Super'], $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all());
        $high = $movements->count();

        return $this->groupRow(
            'Super',
            $movements,
            'EGRESO → Alimentación → Supermercado',
            'ALTA',
            $high,
            $targetCat?->id,
            $targetSub?->id,
            'Nombre histórico Super / excel Super → sub Supermercado (trazabilidad excel_name).'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planComida(): array
    {
        $targetCat = $this->taxonomy->findCategory('Alimentación');
        $targetSub = $this->taxonomy->findSubcategory('Alimentación', 'Comidas');
        $sourceCats = Category::query()->where(function ($q) {
            $q->whereRaw('LOWER(name) = ?', ['comida'])->orWhereRaw('LOWER(name) = ?', ['comidas']);
        })->get();
        $sourceSubs = Subcategory::query()->where(function ($q) {
            $q->whereRaw('LOWER(name) = ?', ['comida'])->orWhereRaw('LOWER(name) = ?', ['comidas']);
        })->get();

        $movements = $this->movementsMatchingNames(['Comida', 'Comidas'], $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all());

        return $this->groupRow(
            'Comida',
            $movements,
            'EGRESO → Alimentación → Comidas',
            'ALTA',
            $movements->count(),
            $targetCat?->id,
            $targetSub?->id,
            'Comida/Comidas como subcategoría de Alimentación (no categoría rígida).'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planMiranda(): array
    {
        $targetCat = $this->taxonomy->findCategory('Gastos familiares');
        $targetSub = $this->taxonomy->findSubcategory('Gastos familiares', 'Miranda');
        $sourceCats = Category::query()->whereRaw('LOWER(name) = ?', ['miranda'])->get();
        $sourceSubs = Subcategory::query()->whereRaw('LOWER(name) = ?', ['miranda'])->get();
        $movements = $this->movementsMatchingNames(['Miranda'], $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all());

        return $this->groupRow(
            'Miranda',
            $movements,
            'EGRESO → Gastos familiares → Miranda',
            'ALTA',
            $movements->count(),
            $targetCat?->id,
            $targetSub?->id,
            'Confirmado usuario: Gastos familiares → Miranda.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planMyu(): array
    {
        $targetCat = $this->taxonomy->findCategory('Muebles y útiles');
        $targetSub = $this->taxonomy->findSubcategory('Muebles y útiles', 'MYU');
        $names = ['MYU', 'MyU', 'Myu', 'Muebles y útiles', 'Muebles y utiles'];
        $sourceCats = Category::query()->where(function ($q) use ($names) {
            foreach ($names as $n) {
                $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($n)]);
            }
        })->get();
        $sourceSubs = Subcategory::query()->where(function ($q) use ($names) {
            foreach ($names as $n) {
                $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($n)]);
            }
        })->get();
        $movements = $this->movementsMatchingNames($names, $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all());

        return $this->groupRow(
            'MYU',
            $movements,
            'EGRESO → Muebles y útiles → MYU',
            'ALTA',
            $movements->count(),
            $targetCat?->id,
            $targetSub?->id,
            'MYU → categoría Muebles y útiles (gasto operativo).'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function planRemotos(): array
    {
        $targetCat = $this->taxonomy->findCategory('Servicios profesionales');
        $targetSub = $this->taxonomy->findSubcategory('Servicios profesionales', 'Remotos');
        $sourceCats = Category::query()->whereRaw('LOWER(name) = ?', ['remotos'])->get();
        $sourceSubs = Subcategory::query()->whereRaw('LOWER(name) = ?', ['remotos'])->get();

        // Excluir los que YA están Servicios profesionales → Remotos.
        $movements = $this->movementsMatchingNames(['Remotos'], $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all())
            ->reject(function (Movement $m) use ($targetCat, $targetSub) {
                return $targetCat && $targetSub
                    && (int) $m->category_id === (int) $targetCat->id
                    && (int) $m->subcategory_id === (int) $targetSub->id;
            })->values();

        // Remotos mal puestos bajo Servicios (egreso) o como categoría suelta.
        $high = $movements->filter(function (Movement $m) {
            $type = $m->type instanceof MovementType ? $m->type->value : (string) $m->getRawOriginal('type');
            $cat = mb_strtolower((string) ($m->category?->name ?? ''));
            // Si es ingreso o categoría Remotos / Servicios con concepto remoto → ALTA a ingreso profesional.
            if ($type === MovementType::Income->value) {
                return true;
            }
            if ($cat === 'remotos') {
                return true;
            }

            return false;
        });

        $highIdSet = $high->pluck('id')->all();
        $ambiguous = $movements->reject(fn ($m) => in_array($m->id, $highIdSet, true));

        $row = $this->groupRow(
            'Remotos',
            $movements,
            'INGRESO → Servicios profesionales → Remotos',
            'ALTA',
            $high->count(),
            $targetCat?->id,
            $targetSub?->id,
            'Remotos NO es Servicios (egreso). Destino: Servicios profesionales → Remotos.'
        );
        $row['ambiguos'] = $ambiguous->count();
        $row['ambiguous_ids'] = $ambiguous->pluck('id')->all();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function planAuto(): array
    {
        $targetCat = $this->taxonomy->findCategory('Automotor');
        $sourceCats = Category::query()->where(function ($q) {
            $q->whereRaw('LOWER(name) = ?', ['auto'])->orWhereRaw('LOWER(name) = ?', ['autos']);
        })->get();
        $sourceSubs = Subcategory::query()->where(function ($q) {
            $q->whereRaw('LOWER(name) = ?', ['auto'])->orWhereRaw('LOWER(name) = ?', ['autos']);
        })->get();
        $movements = $this->movementsMatchingNames(['Auto', 'Autos'], $sourceCats->pluck('id')->all(), $sourceSubs->pluck('id')->all());

        $breakdown = [];
        $highIds = [];
        $ambiguousIds = [];
        $proposals = [];

        foreach ($movements as $m) {
            $sub = $this->inferAutoSubcategory((string) $m->description);
            if ($sub === null) {
                $ambiguousIds[] = $m->id;
                $breakdown['(ambiguo / revisión humana)'] = ($breakdown['(ambiguo / revisión humana)'] ?? 0) + 1;
                $proposals[] = [
                    'id' => $m->id,
                    'description' => $m->description,
                    'proposed_sub' => null,
                    'confidence' => 'BAJA',
                ];

                continue;
            }
            $breakdown[$sub] = ($breakdown[$sub] ?? 0) + 1;
            $highIds[] = $m->id;
            $proposals[] = [
                'id' => $m->id,
                'description' => $m->description,
                'proposed_sub' => $sub,
                'confidence' => 'ALTA',
            ];
        }
        arsort($breakdown);

        $row = $this->groupRow(
            'Auto',
            $movements,
            'EGRESO → Automotor → (sub según descripción inequívoca)',
            'MIXTA',
            count($highIds),
            $targetCat?->id,
            null,
            'Solo auto-aplicar subcategorías inequívocas por descripción; resto a exportación de ambiguos.'
        );
        $row['subcategory_breakdown'] = $breakdown;
        $row['ambiguos'] = count($ambiguousIds);
        $row['ambiguous_ids'] = $ambiguousIds;
        $row['high_ids'] = $highIds;
        $row['proposals_sample'] = array_slice($proposals, 0, 40);

        return $row;
    }

    public function inferAutoSubcategory(string $description): ?string
    {
        $d = mb_strtolower($description);
        if ($this->containsAny($d, self::AUTO_FUEL)) {
            return 'Combustible';
        }
        if ($this->containsAny($d, self::AUTO_INSURANCE)) {
            return 'Seguro';
        }
        if ($this->containsAny($d, self::AUTO_PATENTE)) {
            return 'Patente';
        }
        if ($this->containsAny($d, self::AUTO_PEAJE)) {
            return 'Peajes';
        }
        if ($this->containsAny($d, self::AUTO_PARKING)) {
            return 'Estacionamiento';
        }
        if ($this->containsAny($d, self::AUTO_MAINT)) {
            return 'Mantenimiento';
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, mb_strtolower($n))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $catIds
     * @param  list<int>  $subIds
     * @return Collection<int, Movement>
     */
    private function movementsMatchingNames(array $names, array $catIds, array $subIds): Collection
    {
        return Movement::query()
            ->posted()
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->with(['category', 'subcategory', 'account'])
            ->where(function ($q) use ($catIds, $subIds, $names) {
                if ($catIds !== []) {
                    $q->orWhereIn('category_id', $catIds);
                }
                if ($subIds !== []) {
                    $q->orWhereIn('subcategory_id', $subIds);
                }
                foreach ($names as $n) {
                    $q->orWhere('description', 'like', '%'.$n.'%');
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Movement>  $movements
     * @return array<string, mixed>
     */
    private function groupRow(
        string $grupo,
        Collection $movements,
        string $propuesta,
        string $confianza,
        int $altaCount,
        ?int $targetCategoryId,
        ?int $targetSubcategoryId,
        string $note,
    ): array {
        return [
            'grupo' => $grupo,
            'encontrados' => $movements->count(),
            'propuesta' => $propuesta,
            'confianza' => $confianza,
            'propuesta_alta_confianza' => $altaCount,
            'target_category_id' => $targetCategoryId,
            'target_subcategory_id' => $targetSubcategoryId,
            'note' => $note,
            'sample' => $movements->take(15)->map(fn (Movement $m) => [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'description' => $m->description,
                'amount_ars' => (string) $m->amount_ars,
                'category' => $m->category?->name,
                'subcategory' => $m->subcategory?->name,
                'scope' => $m->scope instanceof \BackedEnum ? $m->scope->value : (string) $m->scope,
            ])->all(),
            'movement_ids' => $movements->pluck('id')->all(),
            'ambiguos' => 0,
            'ambiguous_ids' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function collectAmbiguous(array $groups): array
    {
        $ids = [];
        $grupoById = [];
        foreach ($groups as $g) {
            foreach ($g['ambiguous_ids'] ?? [] as $id) {
                $ids[] = (int) $id;
                $grupoById[(int) $id] = $g['grupo'];
            }
        }
        // Pendientes operativos reales (sin categoría) también van al export.
        $pendingIds = $this->operational->pendingQuery()->pluck('id')->all();
        foreach ($pendingIds as $id) {
            $ids[] = (int) $id;
            $grupoById[(int) $id] = $grupoById[(int) $id] ?? 'Sin categoría';
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $movements = Movement::query()
            ->with(['category', 'subcategory'])
            ->whereIn('id', $ids)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        return $movements->map(function (Movement $m) use ($grupoById) {
            $grupo = $grupoById[$m->id] ?? '—';
            $motivo = match ($grupo) {
                'Auto' => 'Descripción no inequívoca para subcategoría Automotor',
                'Remotos' => 'Posible conflicto egreso/Servicios; revisar naturaleza',
                'Sin categoría' => 'Sin categoría operativa',
                default => 'Requiere decisión humana',
            };

            return [
                'id' => $m->id,
                'date' => $m->movement_date?->toDateString(),
                'description' => $m->description,
                'amount_ars' => (string) $m->amount_ars,
                'scope' => $m->scope instanceof \BackedEnum ? $m->scope->value : (string) $m->scope,
                'type' => $m->type instanceof \BackedEnum ? $m->type->value : (string) $m->getRawOriginal('type'),
                'category' => $m->category?->name,
                'subcategory' => $m->subcategory?->name,
                'grupo' => $grupo,
                'motivo' => $motivo,
            ];
        })->all();
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function estimatePendingResolved(array $groups): int
    {
        // Solo cuenta IDs de alta confianza que hoy están sin categoría.
        $ids = [];
        foreach ($groups as $g) {
            $high = $g['high_ids'] ?? null;
            if (is_array($high)) {
                foreach ($high as $id) {
                    $ids[] = (int) $id;
                }
            } elseif (($g['confianza'] ?? '') === 'ALTA') {
                foreach ($g['movement_ids'] ?? [] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return 0;
        }

        return Movement::query()->whereIn('id', $ids)->whereNull('category_id')->count();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function writeXlsxFile(string $path, array $headers, array $rows): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$headers], $rows), null, 'A1');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
