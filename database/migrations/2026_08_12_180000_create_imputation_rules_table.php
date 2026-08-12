<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imputation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180)->nullable();
            $table->string('condition_type', 40); // description_contains|exact_description|movement_type|category_name
            $table->string('condition_value', 255);
            $table->foreignId('target_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('target_subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->foreignId('target_chart_account_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_manual_override')->default(true);
            $table->unsignedInteger('cached_match_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index(['condition_type', 'condition_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imputation_rules');
    }
};
