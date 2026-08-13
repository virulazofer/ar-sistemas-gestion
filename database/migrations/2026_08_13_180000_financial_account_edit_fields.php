<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('financial_accounts', 'institution')) {
                $table->string('institution', 120)->nullable()->after('alias');
            }
            if (! Schema::hasColumn('financial_accounts', 'holder_name')) {
                $table->string('holder_name', 120)->nullable()->after('institution');
            }
            if (! Schema::hasColumn('financial_accounts', 'card_issue_date')) {
                $table->date('card_issue_date')->nullable()->after('card_expiry_year');
            }
            if (! Schema::hasColumn('financial_accounts', 'default_payment_financial_account_id')) {
                $table->foreignId('default_payment_financial_account_id')
                    ->nullable()
                    ->after('card_issue_date')
                    ->constrained('financial_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('financial_accounts', 'default_payment_financial_account_id')) {
                $table->dropConstrainedForeignId('default_payment_financial_account_id');
            }
            foreach (['card_issue_date', 'holder_name', 'institution'] as $col) {
                if (Schema::hasColumn('financial_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
