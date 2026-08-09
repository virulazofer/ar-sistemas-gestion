<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('unit_cost_ars', 18, 6)->default(0);
            $table->decimal('unit_cost_usd', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 6);
            $table->decimal('total_cost_ars', 18, 2)->default(0);
            $table->decimal('total_cost_usd', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['inventory_movement_id']);
            $table->index(['inventory_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lot_allocations');
    }
};
