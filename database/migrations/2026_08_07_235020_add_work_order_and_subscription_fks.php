<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->after('purchase_item_id')->constrained('work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
        });
        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['work_order_id']);
            $table->dropForeign(['subscription_id']);
        });
    }
};
