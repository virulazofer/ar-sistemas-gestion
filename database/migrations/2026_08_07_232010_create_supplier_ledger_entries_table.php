<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('type', 32); // charge|payment|credit|adjustment|credit_application
            $table->decimal('amount', 18, 2);
            // Perspectiva proveedor en nuestros libros: negativo = le debemos; positivo = a nuestro favor
            $table->decimal('signed_amount', 18, 2);
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
            $table->decimal('amount_ars', 18, 2)->default(0);
            $table->decimal('amount_usd', 18, 2)->default(0);
            $table->date('entry_date');
            $table->time('entry_time');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('posted');
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('financial_movement_id')->nullable()->constrained('movements')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->timestamps();

            $table->index(['supplier_id', 'currency_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
