<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('supplier_code', 64)->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('product_subcategory_id')->nullable()->constrained('product_subcategories')->nullOnDelete();
            $table->string('brand', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('unit', 32)->default('u');
            $table->string('type', 20)->default('physical'); // physical|service
            $table->string('status', 20)->default('active'); // active|inactive
            $table->decimal('stock_min', 18, 4)->default(0);
            $table->decimal('stock_max', 18, 4)->nullable();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            // Cache denormalizado (fuente de verdad = movimientos)
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('qty_reserved', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
