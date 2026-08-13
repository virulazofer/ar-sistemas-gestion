<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ETAPA 11F — rediseño estructural del plan de cuentas (modelo/compatibilidad).
 * No aplica reclasificación masiva de movimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('chart_accounts', 'is_protected')) {
                $table->boolean('is_protected')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('chart_accounts', 'help_text')) {
                $table->string('help_text', 500)->nullable()->after('is_protected');
            }
            if (! Schema::hasColumn('chart_accounts', 'suggested_scope')) {
                $table->string('suggested_scope', 32)->nullable()->after('help_text');
            }
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_accounts', 'chart_account_id')) {
                $table->foreignId('chart_account_id')
                    ->nullable()
                    ->after('currency_id')
                    ->constrained('chart_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('financial_accounts', 'chart_account_id')) {
                $table->dropConstrainedForeignId('chart_account_id');
            }
        });

        Schema::table('chart_accounts', function (Blueprint $table) {
            foreach (['suggested_scope', 'help_text', 'is_protected'] as $col) {
                if (Schema::hasColumn('chart_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
