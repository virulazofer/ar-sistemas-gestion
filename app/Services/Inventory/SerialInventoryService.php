<?php

namespace App\Services\Inventory;

use App\Enums\InventorySerialStatus;
use App\Models\InventoryLot;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SerialInventoryService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string|array{serial: string, internal_code?: string|null, warranty_until?: string|null}>  $serials
     * @return list<InventorySerial>
     */
    public function registerForLot(InventoryLot $lot, Product $product, array $serials, array $meta = []): array
    {
        if ($serials === []) {
            throw new InvalidArgumentException('Se requieren números de serie.');
        }

        $created = [];
        foreach ($serials as $entry) {
            $number = is_array($entry) ? trim((string) ($entry['serial'] ?? '')) : trim((string) $entry);
            if ($number === '') {
                throw new InvalidArgumentException('Número de serie vacío.');
            }

            if (InventorySerial::query()->where('product_id', $product->id)->where('serial_number', $number)->exists()) {
                throw new InvalidArgumentException("El serial {$number} ya existe para este producto.");
            }

            $serial = InventorySerial::query()->create([
                'product_id' => $product->id,
                'inventory_lot_id' => $lot->id,
                'serial_number' => $number,
                'internal_code' => is_array($entry) ? ($entry['internal_code'] ?? null) : null,
                'status' => InventorySerialStatus::Available,
                'supplier_id' => $meta['supplier_id'] ?? $lot->supplier_id,
                'purchase_id' => $meta['purchase_id'] ?? $lot->purchase_id,
                'purchased_at' => $meta['purchased_at'] ?? ($lot->purchase?->purchase_date?->toDateString()),
                'warranty_until' => is_array($entry) ? ($entry['warranty_until'] ?? null) : null,
                'notes' => is_array($entry) ? ($entry['notes'] ?? null) : null,
            ]);
            $created[] = $serial;
        }

        $this->audit->log('inventory_serials_registered', $lot, null, [
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'count' => count($created),
            'serials' => array_map(fn ($s) => $s->serial_number, $created),
        ], 'Seriales registrados en lote');

        return $created;
    }

    public function markConsumed(InventorySerial $serial): void
    {
        $serial = InventorySerial::query()->lockForUpdate()->findOrFail($serial->id);
        if (! $serial->isAvailable() && $serial->status !== InventorySerialStatus::Reserved) {
            throw new InvalidArgumentException('El serial '.$serial->serial_number.' no está disponible.');
        }
        $serial->update(['status' => InventorySerialStatus::Consumed]);
    }

    public function markAvailable(InventorySerial $serial, ?int $newLotId = null): void
    {
        $serial = InventorySerial::query()->lockForUpdate()->findOrFail($serial->id);
        $payload = ['status' => InventorySerialStatus::Available];
        if ($newLotId) {
            $payload['inventory_lot_id'] = $newLotId;
        }
        $serial->update($payload);
    }

    public function findAvailable(Product $product, string $serialNumber): InventorySerial
    {
        $serial = InventorySerial::query()
            ->where('product_id', $product->id)
            ->where('serial_number', $serialNumber)
            ->first();

        if (! $serial) {
            throw new InvalidArgumentException("Serial inexistente: {$serialNumber}.");
        }
        if (! $serial->isAvailable()) {
            throw new InvalidArgumentException("Serial no disponible: {$serialNumber}.");
        }

        return $serial;
    }
}
