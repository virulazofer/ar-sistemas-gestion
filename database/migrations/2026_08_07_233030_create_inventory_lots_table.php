<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
            $table->timestamp('received_at');
            $table->decimal('qty_received', 18, 4);
            $table->decimal('qty_remaining', 18, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('unit_cost_ars', 18, 6)->default(0);
            $table->decimal('unit_cost_usd', 18, 6)->default(0);
            $table->string('status', 20)->default('open'); // open|depleted|voided
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'received_at', 'id']);
            $table->index(['product_id', 'status', 'qty_remaining']);
            $table->index(['purchase_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
