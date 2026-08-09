<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            // Compra opcional; `rate` sigue siendo la venta oficial usada por el negocio.
            $table->decimal('rate_buy', 18, 6)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn('rate_buy');
        });
    }
};
