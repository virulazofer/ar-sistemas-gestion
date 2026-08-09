<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->unsignedInteger('sequence');
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft|confirmed|voided
            $table->string('origin', 30)->default('manual'); // manual|quotation
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->date('sold_on');
            $table->string('currency_code', 3)->default('USD');
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->default(0);
            $table->string('payment_mode', 20)->nullable(); // cash|credit (set on confirm)
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('total_ars', 18, 2)->default(0);
            $table->decimal('total_usd', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->decimal('total_cost_ars', 18, 2)->default(0);
            $table->decimal('total_cost_usd', 18, 2)->default(0);
            $table->decimal('gross_margin', 18, 2)->default(0);
            $table->foreignId('charge_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->foreignId('payment_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->foreignId('financial_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'sold_on']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(1);
            $table->string('item_type', 30);
            $table->string('description');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('quotation_item_id')->nullable()->constrained('quotation_items')->nullOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 6)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_subtotal', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('line_total_ars', 18, 2)->default(0);
            $table->decimal('line_total_usd', 18, 2)->default(0);
            // Costos reales congelados al confirmar
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('line_cost', 18, 2)->default(0);
            $table->decimal('line_cost_ars', 18, 2)->default(0);
            $table->decimal('line_cost_usd', 18, 2)->default(0);
            $table->decimal('line_margin', 18, 2)->default(0);
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->string('equipment_status_before', 30)->nullable();
            $table->boolean('requires_build')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
