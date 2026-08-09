<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Services\Equipment\EquipmentTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EquipmentTypeController extends Controller
{
    public function __construct(
        private readonly EquipmentTypeService $types,
    ) {}

    public function index(): View
    {
        $types = EquipmentType::query()->with('templateItems.category')->orderBy('name')->get();
        $categories = EquipmentComponentCategory::query()->orderBy('sort_order')->get();

        return view('equipment.types.index', compact('types', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code_prefix' => ['required', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->types->createType($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'Tipo de equipo creado.');
    }

    public function storeTemplateItem(Request $request, EquipmentType $equipmentType): RedirectResponse
    {
        $data = $request->validate([
            'component_category_id' => ['required', 'exists:equipment_component_categories,id'],
            'qty_min' => ['nullable', 'integer', 'min:0'],
            'qty_default' => ['required', 'integer', 'min:0'],
            'qty_max' => ['nullable', 'integer', 'min:0'],
            'is_required' => ['nullable', 'boolean'],
            'allow_remove' => ['nullable', 'boolean'],
        ]);
        $data['is_required'] = $request->boolean('is_required');
        $data['allow_remove'] = $request->boolean('allow_remove', true);

        try {
            $this->types->addTemplateItem($equipmentType, $data);
        } catch (\Throwable $e) {
            return back()->withErrors(['component_category_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Plantilla actualizada.');
    }
}
