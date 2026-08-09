<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('entity_type', 40); // clients|suppliers|products|movements|categories
            $table->string('source', 40)->default('file'); // file|google_sheets_future
            $table->string('original_filename')->nullable();
            $table->string('disk')->default('local');
            $table->string('stored_path')->nullable();
            $table->string('status', 20)->default('preview'); // preview|confirmed|rolled_back|failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->json('preview_payload')->nullable();
            $table->json('error_summary')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rollback_reason')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'status']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('notes')->constrained('import_batches')->nullOnDelete();
            $table->string('external_id', 80)->nullable()->after('import_batch_id');
            $table->index(['import_batch_id']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('notes')->constrained('import_batches')->nullOnDelete();
            $table->string('external_id', 80)->nullable()->after('import_batch_id');
            $table->index(['import_batch_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('notes')->constrained('import_batches')->nullOnDelete();
            $table->string('external_id', 80)->nullable()->after('import_batch_id');
            $table->index(['import_batch_id']);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('document_id')->constrained('import_batches')->nullOnDelete();
            $table->string('external_id', 80)->nullable()->after('import_batch_id');
            $table->index(['import_batch_id']);
            $table->unique(['external_id']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('sort_order')->constrained('import_batches')->nullOnDelete();
            $table->index(['import_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
        Schema::table('movements', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('external_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('external_id');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('external_id');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('external_id');
        });
        Schema::dropIfExists('import_batches');
    }
};
