<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\RememberedClassification;
use App\Services\Finance\RememberedClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RememberedClassificationController extends Controller
{
    public function __construct(private readonly RememberedClassificationService $service) {}

    public function index(): View
    {
        $items = RememberedClassification::query()
            ->with(['chartAccount', 'creator'])
            ->orderByDesc('is_active')
            ->orderByDesc('last_used_at')
            ->orderBy('pattern_display')
            ->paginate(50);

        return view('finance.chart_accounts.remembered', compact('items'));
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);

        $hit = $this->service->lookup($data['description'], $data['type']);
        if (! $hit) {
            return response()->json(['match' => null]);
        }

        return response()->json([
            'match' => $hit['match'],
            'chart_account_id' => $hit['suggested_chart_account_id'],
            'scope' => $hit['suggested_scope'],
            'path' => $hit['path'],
            'memory_id' => $hit['memory']->id,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'chart_account_id' => ['required', 'exists:chart_accounts,id'],
            'scope' => ['nullable', Rule::in(['personal', 'professional', 'mixed', 'financial'])],
        ]);

        $memory = $this->service->remember(
            $data['description'],
            $data['type'],
            (int) $data['chart_account_id'],
            $data['scope'] ?? null,
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $memory->id]);
        }

        return back()->with('status', 'Clasificación recordada.');
    }

    public function update(Request $request, RememberedClassification $remembered_classification): RedirectResponse
    {
        $data = $request->validate([
            'chart_account_id' => ['required', 'exists:chart_accounts,id'],
            'scope' => ['nullable', Rule::in(['personal', 'professional', 'mixed', 'financial'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->service->remember(
            $remembered_classification->pattern_display,
            $remembered_classification->movement_type,
            (int) $data['chart_account_id'],
            $data['scope'] ?? null,
        );

        if ($request->has('is_active') && ! $request->boolean('is_active')) {
            $this->service->deactivate($remembered_classification->fresh());
        }

        return back()->with('status', 'Clasificación actualizada.');
    }

    public function deactivate(RememberedClassification $remembered_classification): RedirectResponse|JsonResponse
    {
        $this->service->deactivate($remembered_classification);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', 'Se dejó de recordar esta clasificación.');
    }

    public function destroy(RememberedClassification $remembered_classification): RedirectResponse
    {
        $this->service->delete($remembered_classification);

        return back()->with('status', 'Clasificación eliminada.');
    }

    public function forget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);

        $ok = $this->service->forgetByDescription($data['description'], $data['type']);

        return response()->json(['ok' => $ok]);
    }
}
