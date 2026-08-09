<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->unsignedInteger('sequence');
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            // draft|sent|accepted|rejected|expired|converted|cancelled
            $table->date('quoted_on');
            $table->date('valid_until')->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->default(0);
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('estimated_cost', 18, 2)->default(0);
            $table->decimal('estimated_cost_ars', 18, 2)->default(0);
            $table->decimal('estimated_cost_usd', 18, 2)->default(0);
            $table->decimal('estimated_margin', 18, 2)->default(0);
            $table->decimal('total_ars', 18, 2)->default(0);
            $table->decimal('total_usd', 18, 2)->default(0);
            $table->foreignId('converted_sale_id')->nullable(); // FK later
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(1);
            $table->string('item_type', 30); // product|equipment|service|labor|work_order|free|build_to_order
            $table->string('description');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->nullOnDelete(); // PC a fabricar
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 6)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_subtotal', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('estimated_unit_cost', 18, 6)->default(0);
            $table->decimal('estimated_cost', 18, 2)->default(0);
            $table->decimal('estimated_cost_ars', 18, 2)->default(0);
            $table->decimal('estimated_cost_usd', 18, 2)->default(0);
            $table->decimal('line_total_ars', 18, 2)->default(0);
            $table->decimal('line_total_usd', 18, 2)->default(0);
            $table->boolean('requires_build')->default(false); // arquitectura PC a fabricar
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
