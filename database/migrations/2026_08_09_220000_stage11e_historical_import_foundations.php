<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_holders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->foreignId('account_holder_id')->nullable()->after('currency_id')->constrained('account_holders')->nullOnDelete();
            $table->boolean('is_liability')->default(false)->after('type');
            $table->string('alias', 80)->nullable()->after('name')->index();
            $table->json('aliases')->nullable()->after('alias');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('part_number', 120)->nullable()->after('supplier_code')->index();
            $table->decimal('reference_cost_usd', 18, 4)->nullable()->after('notes');
            $table->string('tax_indicator', 40)->nullable()->after('reference_cost_usd');
            $table->decimal('internal_tax', 18, 4)->nullable()->after('tax_indicator');
            $table->date('list_price_date')->nullable()->after('internal_tax');
            $table->foreignId('default_supplier_id')->nullable()->after('list_price_date')->constrained('suppliers')->nullOnDelete();
            $table->boolean('tracks_units')->default(false)->after('requires_serial');
            $table->text('supplier_comments')->nullable()->after('notes');
        });

        Schema::create('product_supplier_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_code', 80)->nullable()->index();
            $table->string('part_number', 120)->nullable()->index();
            $table->decimal('cost_usd', 18, 4)->nullable();
            $table->string('tax_indicator', 40)->nullable();
            $table->decimal('internal_tax', 18, 4)->nullable();
            $table->date('list_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_code']);
        });

        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('internal_code', 64)->unique();
            $table->string('manufacturer_serial', 120)->nullable()->index();
            $table->string('condition', 32)->default('new');
            $table->string('status', 32)->default('available');
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->timestamp('first_used_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'condition']);
        });

        Schema::create('inventory_unit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_unit_id')->constrained('inventory_units')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('from_condition', 32)->nullable();
            $table->string('to_condition', 32)->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['inventory_unit_id', 'occurred_at']);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->boolean('is_opening_adjustment')->default(false)->after('status');
            $table->string('source_sheet', 80)->nullable()->after('external_id');
            $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
            $table->json('source_payload')->nullable()->after('source_row');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('default_scope', 20)->nullable()->after('scope');
            $table->string('excel_name', 120)->nullable()->after('name')->index();
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('importer_kind', 40)->nullable()->after('entity_type');
            $table->string('file_hash', 64)->nullable()->after('stored_path')->index();
            $table->date('cutover_date')->nullable()->after('file_hash');
            $table->date('period_from')->nullable()->after('cutover_date');
            $table->date('period_to')->nullable()->after('period_from');
            $table->unsignedInteger('rows_green')->default(0)->after('rows_duplicate');
            $table->unsignedInteger('rows_yellow')->default(0)->after('rows_green');
            $table->unsignedInteger('rows_red')->default(0)->after('rows_yellow');
            $table->json('classification_summary')->nullable()->after('preview_payload');
            $table->json('reconciliation_payload')->nullable()->after('classification_summary');
            $table->json('options')->nullable()->after('reconciliation_payload');
        });

        Schema::create('import_row_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('sheet', 80)->nullable();
            $table->unsignedInteger('source_row');
            $table->string('row_hash', 64)->index();
            $table->string('review_status', 20)->default('pending'); // green|yellow|red|excluded|accepted
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('mapping')->nullable();
            $table->json('original')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'sheet', 'source_row']);
            $table->unique(['import_batch_id', 'row_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_traces');

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'importer_kind', 'file_hash', 'cutover_date', 'period_from', 'period_to',
                'rows_green', 'rows_yellow', 'rows_red', 'classification_summary',
                'reconciliation_payload', 'options',
            ]);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['default_scope', 'excel_name']);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->dropColumn(['is_opening_adjustment', 'source_sheet', 'source_row', 'source_payload']);
        });

        Schema::dropIfExists('inventory_unit_events');
        Schema::dropIfExists('inventory_units');
        Schema::dropIfExists('product_supplier_codes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_supplier_id');
            $table->dropColumn([
                'part_number', 'reference_cost_usd', 'tax_indicator', 'internal_tax',
                'list_price_date', 'tracks_units', 'supplier_comments',
            ]);
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_holder_id');
            $table->dropColumn(['is_liability', 'alias', 'aliases']);
        });

        Schema::dropIfExists('account_holders');
    }
};
