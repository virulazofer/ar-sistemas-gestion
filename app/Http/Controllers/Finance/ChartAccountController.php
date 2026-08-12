<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ChartAccountType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use App\Services\Finance\ChartAccountMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartAccountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChartAccountMappingService $mapping,
    ) {}

    public function index(Request $request): View
    {
        $dateFrom = $request->string('from')->toString() ?: null;
        $dateTo = $request->string('to')->toString() ?: null;

        $roots = ChartAccount::query()
            ->with(['children.children.children'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $tree = $this->mapping->reportTree($dateFrom, $dateTo);
        $unassignedMovements = $this->mapping->countMovementsWithoutAccount();
        $assistant = $this->mapping->unassignedAssistant();
        $flat = ChartAccount::query()->orderBy('code')->get();

        return view('finance.chart_accounts.index', compact(
            'roots',
            'flat',
            'tree',
            'unassignedMovements',
            'assistant',
            'dateFrom',
            'dateTo',
        ));
    }

    public function map(): View
    {
        $roots = ChartAccount::query()
            ->with(['children.children' => fn ($q) => $q->orderBy('code')])
            ->whereNull('parent_id')
            ->orderBy('code')
            ->get();

        return view('finance.chart_accounts.map', compact('roots'));
    }

    public function mappingTool(): View
    {
        $categories = Category::query()
            ->with(['subcategories', 'chartAccount'])
            ->orderBy('scope')
            ->orderBy('sort_order')
            ->get();
        $chartAccounts = ChartAccount::query()->orderBy('code')->get();
        $assistant = $this->mapping->unassignedAssistant();
        $typeDefaults = $this->mapping->typeDefaults();
        $unassignedMovements = $this->mapping->countMovementsWithoutAccount();
        $preview = session('chart_mapping_preview');

        return view('finance.chart_accounts.mapping', compact(
            'categories',
            'chartAccounts',
            'assistant',
            'typeDefaults',
            'unassignedMovements',
            'preview',
        ));
    }

    public function saveMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(['category', 'subcategory'])],
            'id' => ['required', 'integer'],
            'chart_account_id' => ['nullable', 'exists:chart_accounts,id'],
        ]);

        $chartId = $data['chart_account_id'] ? (int) $data['chart_account_id'] : null;

        if ($data['target'] === 'category') {
            $cat = Category::query()->findOrFail($data['id']);
            $this->mapping->mapCategory($cat, $chartId);
        } else {
            $sub = Subcategory::query()->findOrFail($data['id']);
            $this->mapping->mapSubcategory($sub, $chartId);
        }

        return back()->with('status', 'Mapeo guardado (regla dinámica; movimientos existentes no se reescriben hasta preview+aplicar).');
    }

    public function saveTypeDefaults(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'income' => ['nullable', 'exists:chart_accounts,id'],
            'expense' => ['nullable', 'exists:chart_accounts,id'],
        ]);

        $this->mapping->saveTypeDefaults([
            'income' => $data['income'] ?? null,
            'expense' => $data['expense'] ?? null,
        ]);

        return back()->with('status', 'Defaults por tipo de movimiento guardados.');
    }

    public function previewApply(Request $request): RedirectResponse
    {
        $preview = $this->mapping->previewApplyToMovements();

        return redirect()
            ->route('chart-accounts.mapping')
            ->with('chart_mapping_preview', $preview)
            ->with('status', 'Vista previa lista. Revisá el impacto y confirmá aplicar.');
    }

    public function applyMapping(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $result = $this->mapping->applyToMovements();

        return redirect()
            ->route('chart-accounts.mapping')
            ->with('status', "Mapeo aplicado: {$result['updated']} actualizados, {$result['skipped']} sin cambio.");
    }

    public function create(Request $request): View
    {
        return view('finance.chart_accounts.create', [
            'types' => ChartAccountType::cases(),
            'parents' => ChartAccount::query()->orderBy('code')->get(),
            'parentId' => $request->integer('parent_id') ?: null,
            'returnTo' => $request->string('return')->toString() ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $account = ChartAccount::query()->create($data);
        $this->audit->log('chart_account_created', $account, null, $account->toArray(), 'Cuenta contable creada');

        if ($request->string('return')->toString() === 'mapping') {
            return redirect()->route('chart-accounts.mapping')->with('status', 'Cuenta creada. Asignala al mapeo.');
        }

        return redirect()->route('chart-accounts.index')->with('status', 'Cuenta creada.');
    }

    public function edit(ChartAccount $chart_account): View
    {
        $usage = $this->usage($chart_account);
        $chart_account->loadCount('children');

        return view('finance.chart_accounts.edit', [
            'account' => $chart_account,
            'types' => ChartAccountType::cases(),
            'parents' => ChartAccount::query()->where('id', '!=', $chart_account->id)->orderBy('code')->get(),
            'reassignTargets' => ChartAccount::query()
                ->where('id', '!=', $chart_account->id)
                ->orderBy('code')
                ->get(),
            'usage' => $usage,
        ]);
    }

    public function update(Request $request, ChartAccount $chart_account): RedirectResponse
    {
        $data = $this->validated($request, $chart_account->id);
        if (($data['parent_id'] ?? null) == $chart_account->id) {
            return back()->withInput()->withErrors(['parent_id' => 'Una cuenta no puede ser padre de sí misma.']);
        }
        $old = $chart_account->toArray();
        $chart_account->update($data);
        $this->audit->log('chart_account_updated', $chart_account, $old, $chart_account->toArray(), 'Cuenta contable actualizada');

        return redirect()->route('chart-accounts.index')->with('status', 'Cuenta actualizada.');
    }

    public function destroy(Request $request, ChartAccount $chart_account): RedirectResponse
    {
        $data = $request->validate([
            'disposition' => ['required', Rule::in(['reassign', 'unassign', 'cancel'])],
            'reassign_to' => [
                'nullable',
                'integer',
                Rule::exists('chart_accounts', 'id')->where(fn ($q) => $q->where('id', '!=', $chart_account->id)),
            ],
            'children_action' => ['nullable', Rule::in(['reparent', 'block'])],
        ]);

        if ($data['disposition'] === 'cancel') {
            return back()->with('status', 'Eliminación cancelada.');
        }

        if ($data['disposition'] === 'reassign' && empty($data['reassign_to'])) {
            return back()->withErrors(['reassign_to' => 'Elegí la cuenta contable de destino.']);
        }

        $childrenCount = $chart_account->children()->count();
        $childrenAction = $data['children_action'] ?? 'reparent';
        if ($childrenCount > 0 && $childrenAction === 'block') {
            return back()->withErrors(['children_action' => 'Esta cuenta tiene hijas. Reasigná o reparentá antes de eliminar.']);
        }

        $targetId = $data['disposition'] === 'reassign' ? (int) $data['reassign_to'] : null;
        if ($targetId !== null && $this->isDescendant($chart_account, $targetId)) {
            return back()->withErrors(['reassign_to' => 'No se puede reasignar a una cuenta hija de la que se elimina.']);
        }

        $usage = $this->usage($chart_account);
        $snapshot = $chart_account->toArray();

        DB::transaction(function () use ($chart_account, $targetId, $childrenCount, $usage, $snapshot) {
            if ($childrenCount > 0) {
                $newParent = $targetId ?? $chart_account->parent_id;
                ChartAccount::query()
                    ->where('parent_id', $chart_account->id)
                    ->update(['parent_id' => $newParent]);
            }

            Category::query()->where('chart_account_id', $chart_account->id)->update(['chart_account_id' => $targetId]);
            Subcategory::query()->where('chart_account_id', $chart_account->id)->update(['chart_account_id' => $targetId]);
            Movement::query()->where('chart_account_id', $chart_account->id)->update(['chart_account_id' => $targetId]);

            $defaults = $this->mapping->typeDefaults();
            $changed = false;
            foreach (['income', 'expense'] as $key) {
                if (($defaults[$key] ?? null) === (int) $chart_account->id) {
                    $defaults[$key] = $targetId;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->mapping->saveTypeDefaults($defaults);
            }

            $chart_account->delete();

            $this->audit->log('chart_account_deleted', null, $snapshot, [
                'disposition' => $targetId ? 'reassign' : 'unassign',
                'reassign_to' => $targetId,
                'children_reparented' => $childrenCount,
                'usage' => $usage,
            ], 'Cuenta contable eliminada');
        });

        $msg = $targetId
            ? 'Cuenta eliminada. Referencias reasignadas.'
            : 'Cuenta eliminada. Referencias quedaron sin asignar.';

        return redirect()->route('chart-accounts.index')->with('status', $msg);
    }

    private function isDescendant(ChartAccount $root, int $candidateId): bool
    {
        $frontier = [$root->id];
        while ($frontier !== []) {
            $ids = ChartAccount::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            if (in_array($candidateId, $ids, true)) {
                return true;
            }
            $frontier = $ids;
        }

        return false;
    }

    /**
     * @return array{categories: int, subcategories: int, movements: int, children: int}
     */
    private function usage(ChartAccount $account): array
    {
        return [
            'categories' => Category::query()->where('chart_account_id', $account->id)->count(),
            'subcategories' => Subcategory::query()->where('chart_account_id', $account->id)->count(),
            'movements' => Movement::query()->where('chart_account_id', $account->id)->count(),
            'children' => $account->children()->count(),
        ];
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('chart_accounts', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::enum(ChartAccountType::class)],
            'parent_id' => ['nullable', 'exists:chart_accounts,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        if ($request->missing('is_active') && $ignoreId === null) {
            $data['is_active'] = true;
        }

        return $data;
    }
}
