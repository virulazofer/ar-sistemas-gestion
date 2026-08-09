<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_preview_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row')->default(0)->index();
            $table->string('decision_type', 40); // date|complex_sale|scope|card|exclude
            $table->string('match_key')->default(''); // reusable pattern (concepto/categoría)
            $table->json('payload');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['import_batch_id', 'decision_type', 'source_row', 'match_key'], 'ipd_batch_type_row_key');
            $table->index(['import_batch_id', 'decision_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_preview_decisions');
    }
};
