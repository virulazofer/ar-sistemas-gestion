<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_component_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug', 64)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug', 64)->unique();
            $table->string('code_prefix', 16);
            $table->unsignedInteger('next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_type_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->constrained('equipment_types')->cascadeOnDelete();
            $table->foreignId('component_category_id')->constrained('equipment_component_categories')->restrictOnDelete();
            $table->unsignedSmallInteger('qty_min')->default(0);
            $table->unsignedSmallInteger('qty_default')->default(1);
            $table->unsignedSmallInteger('qty_max')->nullable(); // null = sin tope estricto
            $table->boolean('is_required')->default(true);
            $table->boolean('allow_remove')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['equipment_type_id', 'component_category_id'], 'equip_type_cat_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_type_template_items');
        Schema::dropIfExists('equipment_types');
        Schema::dropIfExists('equipment_component_categories');
    }
};
