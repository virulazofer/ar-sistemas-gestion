<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('quote_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('rate_type', 32)->default('official_sell');
            // Precisión alta para cotizaciones (ej. ARS por 1 USD)
            $table->decimal('rate', 18, 6);
            $table->string('source', 32); // api|manual|cache_fallback
            $table->string('provider', 64)->nullable();
            $table->timestamp('rate_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('provider_payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Nombre explícito: MySQL limita identificadores a 64 caracteres.
            $table->index(
                ['base_currency_id', 'quote_currency_id', 'rate_type', 'rate_at'],
                'exchange_rates_lookup_idx'
            );
            $table->index('rate_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
