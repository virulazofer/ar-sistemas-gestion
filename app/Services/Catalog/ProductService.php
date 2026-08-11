<?php

namespace App\Services\Catalog;

use App\Enums\ProductType;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProductService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $payload = $this->normalize($data);
            if (Product::query()->where('sku', $payload['sku'])->exists()) {
                throw new InvalidArgumentException('El SKU ya existe.');
            }

            if (! ($payload['inventory_location_id'] ?? null) && $payload['type'] === ProductType::Physical->value) {
                $payload['inventory_location_id'] = InventoryLocation::defaultLocation()->id;
            }

            $product = Product::query()->create(array_merge($payload, [
                'qty_on_hand' => 0,
                'qty_reserved' => 0,
            ]));
            $this->audit->log('product_created', $product, null, $product->toArray(), 'Producto creado');

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $old = $product->toArray();
            $payload = $this->normalize($data, $product->id);
            $product->update($payload);
            $this->audit->log('product_updated', $product, $old, $product->fresh()->toArray(), 'Producto actualizado');

            return $product->fresh();
        });
    }

    /**
     * Baja masiva segura: elimina solo si no hay relaciones; si las hay, archiva (inactive).
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, archived: int, skipped: int}
     */
    public function bulkDeleteOrArchive(array $ids): array
    {
        $deleted = 0;
        $archived = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $product = Product::query()->find($id);
            if (! $product) {
                $skipped++;
                continue;
            }

            $hasRelations = $product->lots()->exists()
                || $product->inventoryMovements()->exists()
                || $product->serials()->exists()
                || $product->units()->exists()
                || DB::table('sale_items')->where('product_id', $product->id)->exists()
                || DB::table('quotation_items')->where('product_id', $product->id)->exists()
                || DB::table('purchase_items')->where('product_id', $product->id)->exists()
                || DB::table('equipment_components')->where('product_id', $product->id)->exists();

            if ($hasRelations) {
                $product->update(['status' => Product::STATUS_INACTIVE]);
                $this->audit->log('product_archived', $product, null, ['id' => $product->id], 'Producto archivado (relaciones)');
                $archived++;
            } else {
                $this->audit->log('product_deleted', $product, $product->toArray(), null, 'Producto eliminado');
                $product->delete();
                $deleted++;
            }
        }

        return compact('deleted', 'archived', 'skipped');
    }

    public function createCategory(string $name, int $sortOrder = 0): ProductCategory
    {
        $slug = Str::slug($name);

        return ProductCategory::query()->create([
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    public function createSubcategory(ProductCategory $category, string $name, int $sortOrder = 0): ProductSubcategory
    {
        return ProductSubcategory::query()->create([
            'product_category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
    }

    private function normalize(array $data, ?int $ignoreId = null): array
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            throw new InvalidArgumentException('El SKU es obligatorio.');
        }

        $type = ProductType::from((string) ($data['type'] ?? ProductType::Physical->value));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('El nombre es obligatorio.');
        }

        if (! empty($data['product_subcategory_id']) && ! empty($data['product_category_id'])) {
            $sub = ProductSubcategory::query()->find($data['product_subcategory_id']);
            if ($sub && (int) $sub->product_category_id !== (int) $data['product_category_id']) {
                throw new InvalidArgumentException('La subcategoría no pertenece a la categoría.');
            }
        }

        if ($ignoreId && Product::query()->where('sku', $sku)->where('id', '!=', $ignoreId)->exists()) {
            throw new InvalidArgumentException('El SKU ya existe.');
        }

        return [
            'sku' => $sku,
            'supplier_code' => $data['supplier_code'] ?? null,
            'part_number' => $data['part_number'] ?? null,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'product_category_id' => $data['product_category_id'] ?? null,
            'product_subcategory_id' => $data['product_subcategory_id'] ?? null,
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'unit' => $data['unit'] ?? 'u',
            'type' => $type->value,
            'requires_serial' => (bool) ($data['requires_serial'] ?? false),
            'tracks_units' => (bool) ($data['tracks_units'] ?? false),
            'status' => $data['status'] ?? Product::STATUS_ACTIVE,
            'stock_min' => $data['stock_min'] ?? 0,
            'stock_max' => $data['stock_max'] ?? null,
            'inventory_location_id' => $data['inventory_location_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
