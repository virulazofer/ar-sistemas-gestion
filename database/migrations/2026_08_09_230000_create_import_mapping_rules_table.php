<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_type', 40); // account_alias|client_alias|scope_default|category_map|ignore_flag
            $table->string('match_key'); // e.g. SubCuenta "Patagonia" or flag "cc_combinado_ingreso"
            $table->string('match_value')->nullable();
            $table->json('action'); // structured action payload
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_apply')->default(true);
            $table->unsignedInteger('times_applied')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['rule_type', 'match_key']);
            $table->index(['is_active', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_rules');
    }
};
