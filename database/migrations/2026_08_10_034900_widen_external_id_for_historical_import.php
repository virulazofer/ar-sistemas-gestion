<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['movements', 'client_ledger_entries', 'supplier_ledger_entries', 'sales'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'external_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // Unique indexes on MySQL may need drop/recreate when changing string length.
                try {
                    $blueprint->dropUnique(['external_id']);
                } catch (\Throwable) {
                    // ignore if missing
                }
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('external_id', 191)->nullable()->change();
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(['external_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['movements', 'client_ledger_entries', 'supplier_ledger_entries', 'sales'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'external_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                try {
                    $blueprint->dropUnique(['external_id']);
                } catch (\Throwable) {
                }
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('external_id', 80)->nullable()->change();
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(['external_id']);
            });
        }
    }
};
