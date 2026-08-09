<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number')->default(1);
            // Preparado para Etapa 5 (sin tabla products aún)
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('sku', 64)->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->string('unit', 32)->default('u');
            $table->decimal('unit_price', 18, 6);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->decimal('line_subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('unit_cost_ars', 18, 6)->default(0);
            $table->decimal('unit_cost_usd', 18, 6)->default(0);
            $table->decimal('line_total_ars', 18, 2)->default(0);
            $table->decimal('line_total_usd', 18, 2)->default(0);
            // Prep FIFO/stock Etapa 5: cantidad aún no ingresada a lotes
            $table->decimal('qty_pending_stock', 18, 4);
            $table->boolean('stock_receipt_ready')->default(true);
            $table->timestamps();

            $table->index(['purchase_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
