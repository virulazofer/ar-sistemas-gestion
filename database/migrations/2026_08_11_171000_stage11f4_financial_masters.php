<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'control_cc_desde')) {
                $table->date('control_cc_desde')->nullable()->after('notes');
            }
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_accounts', 'cbu_cvu')) {
                $table->string('cbu_cvu', 22)->nullable()->after('external_identifier');
            }
            if (! Schema::hasColumn('financial_accounts', 'cuit')) {
                $table->string('cuit', 11)->nullable()->after('cbu_cvu');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_last4')) {
                $table->string('card_last4', 4)->nullable()->after('cuit');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_brand')) {
                $table->string('card_brand', 40)->nullable()->after('card_last4');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_holder')) {
                $table->string('card_holder', 120)->nullable()->after('card_brand');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_expiry_month')) {
                $table->unsignedTinyInteger('card_expiry_month')->nullable()->after('card_holder');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_expiry_year')) {
                $table->unsignedSmallInteger('card_expiry_year')->nullable()->after('card_expiry_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'control_cc_desde')) {
                $table->dropColumn('control_cc_desde');
            }
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            foreach (['cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'card_holder', 'card_expiry_month', 'card_expiry_year'] as $col) {
                if (Schema::hasColumn('financial_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
