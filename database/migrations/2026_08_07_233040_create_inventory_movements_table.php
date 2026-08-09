<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('transfer_group_id')->nullable()->index();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('type', 32); // receipt|issue|adjustment_in|adjustment_out|transfer_out|transfer_in|reserve|release|consume
            $table->decimal('quantity', 18, 4); // siempre positivo
            $table->decimal('signed_qty_on_hand', 18, 4)->default(0); // efecto sobre stock físico
            $table->decimal('signed_qty_reserved', 18, 4)->default(0); // efecto sobre reservado
            $table->date('movement_date');
            $table->time('movement_time');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('inventory_location_to_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('total_cost', 18, 6)->nullable();
            $table->decimal('total_cost_ars', 18, 2)->nullable();
            $table->decimal('total_cost_usd', 18, 2)->nullable();
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'movement_date', 'id']);
            $table->index(['type', 'status']);
            $table->index(['purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
