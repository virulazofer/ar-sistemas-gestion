<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Movement;
use App\Services\Finance\MovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MovementController extends Controller
{
    public function __construct(private readonly MovementService $movements) {}

    public function index(Request $request): View
    {
        $movements = Movement::query()
            ->with(['account.currency', 'category', 'subcategory', 'user'])
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('movement_date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('movement_date', '<=', $request->string('date_to')))
            ->latest('movement_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('finance.movements.index', compact('movements'));
    }

    public function show(Movement $movement): View
    {
        $movement->load(['account.currency', 'category', 'subcategory', 'user', 'exchangeRate', 'voidedByUser']);

        $pair = null;
        if ($movement->transfer_id) {
            $pair = Movement::query()
                ->with('account.currency')
                ->where('transfer_id', $movement->transfer_id)
                ->where('id', '!=', $movement->id)
                ->first();
        }

        return view('finance.movements.show', compact('movement', 'pair'));
    }

    public function void(Request $request, Movement $movement): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->movements->void($movement, $data['void_reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['void_reason' => $e->getMessage()]);
        }

        return redirect()->route('movements.show', $movement)->with('status', 'Movimiento anulado.');
    }
}
