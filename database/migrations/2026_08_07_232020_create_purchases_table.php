<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('purchase_date');
            $table->string('voucher_type', 32)->nullable(); // factura|remito|ticket|otro
            $table->string('voucher_letter', 4)->nullable();
            $table->string('voucher_number', 64)->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0); // IVA y similares agregados
            $table->decimal('other_taxes', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('total_ars', 18, 2)->default(0);
            $table->decimal('total_usd', 18, 2)->default(0);
            $table->string('payment_mode', 20); // cash|credit
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('financial_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->foreignId('obligation_ledger_entry_id')->nullable()->constrained('supplier_ledger_entries')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_date', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index(['payment_mode', 'status']);
        });

        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->foreign('purchase_id')->references('id')->on('purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['purchase_id']);
        });
        Schema::dropIfExists('purchases');
    }
};
