<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('code', 32)->nullable()->unique()->after('uuid');
            $table->string('type', 32)->nullable()->after('code');
            $table->string('content_hash', 64)->nullable()->index()->after('size');
            $table->string('status', 40)->nullable()->index()->after('content_hash');
            $table->string('optimization_status', 32)->nullable()->after('status');
            $table->boolean('keep_original')->default(false)->after('optimization_status');
            $table->string('original_path')->nullable()->after('path');
            $table->string('optimized_path')->nullable()->after('original_path');
            $table->string('preview_path')->nullable()->after('optimized_path');
            $table->unsignedBigInteger('original_size')->nullable()->after('preview_path');
            $table->unsignedBigInteger('optimized_size')->nullable()->after('original_size');
            $table->timestamp('original_deleted_at')->nullable()->after('optimized_size');
            $table->string('source', 32)->nullable()->after('original_deleted_at');
            $table->json('meta')->nullable()->after('notes');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'uuid',
                'code',
                'type',
                'content_hash',
                'status',
                'optimization_status',
                'keep_original',
                'original_path',
                'optimized_path',
                'preview_path',
                'original_size',
                'optimized_size',
                'original_deleted_at',
                'source',
                'meta',
            ]);
        });
    }
};
