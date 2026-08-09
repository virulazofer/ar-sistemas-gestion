<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('equipment_type_id')->constrained('equipment_types')->restrictOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('assembled');
            $table->timestamp('assembled_at')->nullable();
            $table->timestamp('disassembled_at')->nullable();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('total_cost_ars', 18, 2)->default(0);
            $table->decimal('total_cost_usd', 18, 2)->default(0);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'equipment_type_id']);
            $table->index(['assembled_at']);
        });

        Schema::create('equipment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments')->restrictOnDelete();
            $table->foreignId('component_category_id')->nullable()->constrained('equipment_component_categories')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('inventory_lot_allocation_id')->nullable()->constrained('inventory_lot_allocations')->nullOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('unit_cost_ars', 18, 6)->default(0);
            $table->decimal('unit_cost_usd', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('total_cost_ars', 18, 2)->default(0);
            $table->decimal('total_cost_usd', 18, 2)->default(0);
            $table->string('status', 20)->default('installed'); // installed|removed|recovered
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason')->nullable();
            $table->foreignId('replaced_by_component_id')->nullable()->constrained('equipment_components')->nullOnDelete();
            $table->date('warranty_until')->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'status']);
            $table->index(['inventory_serial_id']);
        });

        Schema::create('equipment_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_status_logs');
        Schema::dropIfExists('equipment_components');
        Schema::dropIfExists('equipments');
    }
};
