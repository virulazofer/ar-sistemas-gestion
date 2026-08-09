<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreign('converted_sale_id')->references('id')->on('sales')->nullOnDelete();
        });

        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('quote_id')->constrained('sales')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('work_order_id')->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['converted_sale_id']);
        });
    }
};
