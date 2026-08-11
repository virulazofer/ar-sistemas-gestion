<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging/MySQL: hacer nullable financial_account_id en receipts
 * (sqlite ya nace nullable desde create 11F-3 actualizado).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['financial_account_id']);
        });

        DB::statement('ALTER TABLE receipts MODIFY financial_account_id BIGINT UNSIGNED NULL');

        Schema::table('receipts', function (Blueprint $table) {
            $table->foreign('financial_account_id')
                ->references('id')
                ->on('financial_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['financial_account_id']);
        });

        DB::statement('ALTER TABLE receipts MODIFY financial_account_id BIGINT UNSIGNED NOT NULL');

        Schema::table('receipts', function (Blueprint $table) {
            $table->foreign('financial_account_id')
                ->references('id')
                ->on('financial_accounts')
                ->restrictOnDelete();
        });
    }
};
