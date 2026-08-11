<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly StockBalanceService $balances,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->get('q', ''));
        $brand = trim((string) $request->get('brand', ''));
        $categoryId = $request->integer('product_category_id') ?: null;

        $products = Product::query()
            ->with(['category', 'subcategory', 'location'])
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('brand', 'like', "%{$q}%")
                    ->orWhere('model', 'like', "%{$q}%")
                    ->orWhere('supplier_code', 'like', "%{$q}%")
                    ->orWhere('part_number', 'like', "%{$q}%");
            }))
            ->when($brand !== '', fn ($query) => $query->where('brand', $brand))
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $brands = Product::query()->whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand');
        $categories = ProductCategory::query()->orderBy('name')->get(['id', 'name']);

        return view('products.index', compact('products', 'q', 'brand', 'categoryId', 'brands', 'categories'));
    }

    public function create(): View
    {
        return view('products.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['requires_serial'] = $request->boolean('requires_serial');
        try {
            $product = $this->products->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Producto creado.');
    }

    public function show(Product $product): View
    {
        $product->load(['category', 'subcategory', 'location']);
        $snapshot = $this->balances->snapshot($product);
        $value = $product->tracksStock()
            ? $this->balances->inventoryValue($product->id)
            : ['qty' => '0', 'value_ars' => '0.00', 'value_usd' => '0.00', 'lots' => 0];
        $lots = $product->lots()->with('currency')->latest('received_at')->limit(20)->get();
        $movements = $product->inventoryMovements()->with('user')->latest('id')->limit(20)->get();

        return view('products.show', compact('product', 'snapshot', 'value', 'lots', 'movements'));
    }

    public function edit(Product $product): View
    {
        return view('products.edit', array_merge($this->formData(), compact('product')));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);
        $data['requires_serial'] = $request->boolean('requires_serial');
        try {
            $this->products->update($product, $data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Producto actualizado.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:products,id'],
        ]);

        $result = $this->products->bulkDeleteOrArchive($data['ids']);

        return redirect()
            ->route('products.index')
            ->with('status', "Productos: {$result['deleted']} eliminados, {$result['archived']} archivados (con relaciones), {$result['skipped']} omitidos.");
    }

    private function formData(): array
    {
        return [
            'categories' => ProductCategory::query()->with('subcategories')->orderBy('sort_order')->get(),
            'locations' => InventoryLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'types' => ProductType::cases(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'supplier_code' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_category_id' => ['nullable', 'exists:product_categories,id'],
            'product_subcategory_id' => ['nullable', 'exists:product_subcategories,id'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:32'],
            'type' => ['required', Rule::in(['physical', 'service'])],
            'requires_serial' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'stock_min' => ['nullable', 'numeric', 'min:0'],
            'stock_max' => ['nullable', 'numeric', 'min:0'],
            'inventory_location_id' => ['nullable', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
