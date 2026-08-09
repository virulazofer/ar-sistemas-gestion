<?php

namespace App\Services\Equipment;

use App\Models\EquipmentComponentCategory;
use App\Models\EquipmentType;
use App\Models\EquipmentTypeTemplateItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EquipmentTypeService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function createType(array $data): EquipmentType
    {
        return DB::transaction(function () use ($data) {
            $name = trim((string) ($data['name'] ?? ''));
            $prefix = strtoupper(trim((string) ($data['code_prefix'] ?? '')));
            if ($name === '' || $prefix === '') {
                throw new InvalidArgumentException('Nombre y prefijo de código son obligatorios.');
            }

            $type = EquipmentType::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'code_prefix' => $prefix,
                'next_sequence' => (int) ($data['next_sequence'] ?? 1),
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->log('equipment_type_created', $type, null, $type->toArray(), 'Tipo de equipo creado');

            return $type;
        });
    }

    public function createCategory(string $name, int $sortOrder = 0): EquipmentComponentCategory
    {
        return EquipmentComponentCategory::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    public function addTemplateItem(EquipmentType $type, array $data): EquipmentTypeTemplateItem
    {
        $item = EquipmentTypeTemplateItem::query()->create([
            'equipment_type_id' => $type->id,
            'component_category_id' => $data['component_category_id'],
            'qty_min' => $data['qty_min'] ?? 0,
            'qty_default' => $data['qty_default'] ?? 1,
            'qty_max' => $data['qty_max'] ?? null,
            'is_required' => $data['is_required'] ?? true,
            'allow_remove' => $data['allow_remove'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->log('equipment_template_item_created', $item, null, $item->toArray(), 'Ítem de plantilla');

        return $item;
    }

    /**
     * @return list<array{component_category_id: int, category: string, qty_default: int, qty_min: int, qty_max: ?int, is_required: bool, allow_remove: bool}>
     */
    public function suggestedComponents(EquipmentType $type): array
    {
        return $type->templateItems()->with('category')->get()->map(fn (EquipmentTypeTemplateItem $item) => [
            'component_category_id' => $item->component_category_id,
            'category' => $item->category->name,
            'qty_default' => $item->qty_default,
            'qty_min' => $item->qty_min,
            'qty_max' => $item->qty_max,
            'is_required' => $item->is_required,
            'allow_remove' => $item->allow_remove,
        ])->all();
    }

    public function nextCode(EquipmentType $type): string
    {
        $type = EquipmentType::query()->lockForUpdate()->findOrFail($type->id);
        $seq = $type->next_sequence;
        $code = sprintf('%s-%06d', $type->code_prefix, $seq);
        $type->update(['next_sequence' => $seq + 1]);

        return $code;
    }
}
