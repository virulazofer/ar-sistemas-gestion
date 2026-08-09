<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->string('serial_number', 120);
            $table->string('internal_code', 64)->nullable();
            $table->string('status', 20)->default('available'); // available|reserved|consumed|returned
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->date('purchased_at')->nullable();
            $table->date('warranty_until')->nullable(); // prep garantías
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'serial_number']);
            $table->index(['inventory_lot_id', 'status']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_serials');
    }
};
