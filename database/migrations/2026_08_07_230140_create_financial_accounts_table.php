<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32); // cash|bank|wallet|other
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('status', 20)->default('active'); // active|inactive
            $table->string('external_identifier')->nullable();
            $table->text('description')->nullable();
            // Saldo denormalizado SOLO como cache; fuente de verdad = movimientos posted
            $table->decimal('cached_balance', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index('currency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
