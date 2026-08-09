<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('transfer_id')->nullable()->index();
            $table->date('movement_date');
            $table->time('movement_time');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('scope', 20); // personal|professional
            $table->string('type', 20); // income|expense|transfer_out|transfer_in
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->decimal('exchange_rate_value', 18, 6)->nullable();
            $table->timestamp('exchange_rate_at')->nullable();
            $table->decimal('amount_ars', 18, 2)->default(0);
            $table->decimal('amount_usd', 18, 2)->default(0);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->foreignId('chart_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();

            // Preparados para etapas futuras (sin FK aún: tablas no existen)
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('work_order_id')->nullable()->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();

            $table->timestamps();

            $table->index(['movement_date', 'status']);
            $table->index(['financial_account_id', 'status']);
            $table->index(['scope', 'type', 'status']);
            $table->index(['type', 'status', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
