<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remembered_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('pattern_normalized', 255);
            $table->string('pattern_display', 255);
            $table->string('movement_type', 20); // income|expense
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->cascadeOnDelete();
            $table->string('scope', 32)->nullable(); // personal|professional|mixed|financial
            $table->string('match_kind', 20)->default('exact'); // exact (confirmed memory)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pattern_normalized', 'movement_type'], 'remembered_class_pattern_type_uq');
            $table->index(['is_active', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remembered_classifications');
    }
};
