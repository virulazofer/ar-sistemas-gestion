<?php

namespace App\Http\Controllers\Equipment;

use App\Enums\EquipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentComponent;
use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Models\InventoryLocation;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Services\Equipment\EquipmentAssemblyService;
use App\Services\Equipment\EquipmentTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EquipmentController extends Controller
{
    public function __construct(
        private readonly EquipmentAssemblyService $assembly,
        private readonly EquipmentTypeService $types,
    ) {}

    public function index(): View
    {
        $equipments = Equipment::query()->with(['type', 'location'])->latest('id')->paginate(25);

        return view('equipment.index', compact('equipments'));
    }

    public function create(): View
    {
        $equipmentTypes = EquipmentType::query()->where('is_active', true)->with('templateItems.category')->orderBy('name')->get();
        $products = Product::query()->where('type', 'physical')->where('status', 'active')->orderBy('name')->get();
        $locations = InventoryLocation::query()->where('is_active', true)->orderBy('name')->get();
        $categories = EquipmentComponentCategory::query()->where('is_active', true)->orderBy('sort_order')->get();
        $serials = InventorySerial::query()->available()->with('product')->orderBy('serial_number')->get();

        return view('equipment.create', compact('equipmentTypes', 'products', 'locations', 'categories', 'serials'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'equipment_type_id' => ['required', 'exists:equipment_types,id'],
            'name' => ['required', 'string', 'max:180'],
            'inventory_location_id' => ['nullable', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.product_id' => ['required', 'exists:products,id'],
            'components.*.quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.component_category_id' => ['nullable', 'exists:equipment_component_categories,id'],
            'components.*.serial_number' => ['nullable', 'string', 'max:120'],
            'components.*.inventory_serial_id' => ['nullable', 'exists:inventory_serials,id'],
        ]);

        try {
            $equipment = $this->assembly->assemble($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['components' => $e->getMessage()]);
        }

        return redirect()->route('equipment.show', $equipment)->with('status', 'Equipo armado: '.$equipment->code);
    }

    public function show(Equipment $equipment): View
    {
        $equipment->load([
            'type',
            'location',
            'user',
            'components.product',
            'components.serial',
            'components.lot',
            'components.currency',
            'components.category',
            'statusLogs.user',
        ]);
        $statuses = EquipmentStatus::cases();
        $products = Product::query()->where('type', 'physical')->where('status', 'active')->orderBy('name')->get();
        $serials = InventorySerial::query()->available()->with('product')->orderBy('serial_number')->get();

        return view('equipment.show', compact('equipment', 'statuses', 'products', 'serials'));
    }

    public function changeStatus(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_column(EquipmentStatus::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->assembly->changeStatus($equipment, EquipmentStatus::from($data['status']), $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Estado actualizado.');
    }

    public function disassemble(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->assembly->disassemble($equipment, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Equipo desarmado. Componentes recuperados a stock.');
    }

    public function replaceComponent(Request $request, Equipment $equipment, EquipmentComponent $component): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'inventory_serial_id' => ['nullable', 'exists:inventory_serials,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->assembly->replaceComponent($equipment, $component, $data, $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Componente reemplazado.');
    }

    public function template(EquipmentType $equipmentType)
    {
        return response()->json($this->types->suggestedComponents($equipmentType));
    }
}
