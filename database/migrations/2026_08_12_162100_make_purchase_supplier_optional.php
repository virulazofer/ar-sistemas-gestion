<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'counterparty_name')) {
                $table->string('counterparty_name', 180)->nullable()->after('supplier_id');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite tests: rebuild nullable FK without doctrine/dbal.
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });
            Schema::table('purchases', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable()->change();
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            });

            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
        });

        DB::statement('ALTER TABLE purchases MODIFY supplier_id BIGINT UNSIGNED NULL');

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            if (Schema::hasColumn('purchases', 'counterparty_name')) {
                $table->dropColumn('counterparty_name');
            }
        });

        if ($driver === 'sqlite') {
            Schema::table('purchases', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
                $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            });

            return;
        }

        DB::statement('ALTER TABLE purchases MODIFY supplier_id BIGINT UNSIGNED NOT NULL');
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }
};
