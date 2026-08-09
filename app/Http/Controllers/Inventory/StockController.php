<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly StockBalanceService $balances,
    ) {}

    public function index(): View
    {
        $products = Product::query()
            ->where('type', 'physical')
            ->with(['category', 'location'])
            ->orderBy('name')
            ->paginate(30);
        $value = $this->balances->inventoryValue();

        return view('stock.index', compact('products', 'value'));
    }

    public function movements(Request $request): View
    {
        $movements = InventoryMovement::query()
            ->with(['product', 'user', 'location'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('stock.movements', compact('movements'));
    }

    public function createAdjust(Product $product): View
    {
        return view('stock.adjust', compact('product'));
    }

    public function storeAdjust(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'movement_date' => ['required', 'date'],
        ]);

        try {
            if ($data['direction'] === 'in') {
                $this->inventory->adjustIn($product, $data);
            } else {
                $this->inventory->adjustOut($product, $data);
            }
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Ajuste registrado.');
    }

    public function createConsume(Product $product): View
    {
        return view('stock.consume', compact('product'));
    }

    public function storeConsume(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'movement_date' => ['required', 'date'],
        ]);

        try {
            $this->inventory->consume($product, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Consumo registrado (FIFO).');
    }

    public function createReserve(Product $product): View
    {
        return view('stock.reserve', compact('product'));
    }

    public function storeReserve(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:reserve,release'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'movement_date' => ['required', 'date'],
        ]);

        try {
            if ($data['action'] === 'reserve') {
                $this->inventory->reserve($product, $data);
            } else {
                $this->inventory->release($product, $data);
            }
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Reserva actualizada.');
    }

    public function createTransfer(Product $product): View
    {
        $locations = InventoryLocation::query()->where('is_active', true)->orderBy('name')->get();

        return view('stock.transfer', compact('product', 'locations'));
    }

    public function storeTransfer(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'inventory_location_id' => ['required', 'exists:inventory_locations,id'],
            'inventory_location_to_id' => ['required', 'exists:inventory_locations,id', 'different:inventory_location_id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'movement_date' => ['required', 'date'],
        ]);

        try {
            $this->inventory->transfer($product, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Transferencia registrada.');
    }

    public function rebuildForm(): View
    {
        $products = Product::query()->where('type', 'physical')->orderBy('name')->get();

        return view('stock.rebuild', compact('products'));
    }

    public function rebuild(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['all'])) {
            $count = $this->balances->rebuildAll();

            return back()->with('status', "Stock reconstruido para {$count} productos.");
        }

        if (empty($data['product_id'])) {
            return back()->withErrors(['product_id' => 'Seleccioná un producto o reconstruir todo.']);
        }

        $product = Product::query()->findOrFail($data['product_id']);
        $result = $this->balances->rebuildProduct($product);

        return back()->with('status', 'Reconstruido: actual '.$result['qty_on_hand'].', reservado '.$result['qty_reserved'].'.');
    }
}
