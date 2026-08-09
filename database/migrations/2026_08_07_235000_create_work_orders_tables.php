<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('work_order_type_id')->constrained('work_order_types')->restrictOnDelete();
            $table->string('status', 32)->default('open');
            $table->string('priority', 20)->default('normal'); // low|normal|high|urgent
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('solution')->nullable();
            $table->text('notes')->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('total_cost', 18, 6)->default(0);
            $table->decimal('total_cost_ars', 18, 2)->default(0);
            $table->decimal('total_cost_usd', 18, 2)->default(0);
            $table->decimal('total_price', 18, 6)->default(0);
            $table->decimal('total_price_ars', 18, 2)->default(0);
            $table->decimal('total_price_usd', 18, 2)->default(0);
            $table->foreignId('client_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'opened_at']);
        });

        Schema::create('work_order_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->string('external_manufacturer')->nullable();
            $table->string('external_model')->nullable();
            $table->string('external_serial')->nullable();
            $table->string('external_label')->nullable();
            $table->text('external_description')->nullable();
            $table->timestamps();
        });

        Schema::create('work_order_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->text('client_reported_issue')->nullable();
            $table->text('technical_diagnosis');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('diagnosed_at');
            $table->timestamps();
        });

        Schema::create('work_order_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('hours', 10, 2)->nullable();
            $table->decimal('cost_amount', 18, 6)->default(0);
            $table->decimal('price_amount', 18, 6)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('cost_ars', 18, 2)->default(0);
            $table->decimal('cost_usd', 18, 2)->default(0);
            $table->decimal('price_ars', 18, 2)->default(0);
            $table->decimal('price_usd', 18, 2)->default(0);
            $table->string('status', 20)->default('pending'); // pending|done|cancelled
            $table->timestamps();
        });

        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('price_unit', 18, 6)->default(0);
            $table->decimal('price_total', 18, 6)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('price_ars', 18, 2)->default(0);
            $table->decimal('price_usd', 18, 2)->default(0);
            // Costos FIFO congelados al consumir
            $table->decimal('cost_unit', 18, 6)->default(0);
            $table->decimal('cost_total', 18, 6)->default(0);
            $table->decimal('cost_ars', 18, 2)->default(0);
            $table->decimal('cost_usd', 18, 2)->default(0);
            $table->string('status', 20)->default('pending'); // pending|consumed|voided
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('inventory_lot_allocation_id')->nullable()->constrained('inventory_lot_allocations')->nullOnDelete();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('work_order_tasks');
        Schema::dropIfExists('work_order_diagnoses');
        Schema::dropIfExists('work_order_assets');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('work_order_types');
    }
};
