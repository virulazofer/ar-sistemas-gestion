<?php

use App\Models\Movement;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');
            $table->text('observations')->nullable()->after('description');
        });

        Schema::create('movement_edit_audits', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80)->default('movement');
            $table->foreignId('movement_id')->constrained('movements')->cascadeOnDelete();
            $table->string('movement_code', 20)->nullable()->index();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['movement_id', 'created_at']);
            $table->index(['field', 'created_at']);
        });

        Schema::create('chart_account_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->cascadeOnDelete();
            $table->unsignedInteger('use_count')->default(1);
            $table->timestamp('last_used_at');
            $table->timestamps();

            $table->unique(['user_id', 'chart_account_id']);
            $table->index(['user_id', 'last_used_at']);
            $table->index(['user_id', 'use_count']);
        });

        $this->backfillCodes();
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_account_usages');
        Schema::dropIfExists('movement_edit_audits');

        Schema::table('movements', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'observations']);
        });
    }

    private function backfillCodes(): void
    {
        $byYear = [];

        Movement::query()
            ->orderBy('id')
            ->select(['id', 'movement_date'])
            ->chunkById(500, function ($rows) use (&$byYear) {
                foreach ($rows as $row) {
                    $year = $row->movement_date?->format('Y') ?: now()->format('Y');
                    $byYear[$year] = ($byYear[$year] ?? 0) + 1;
                    $seq = $byYear[$year];
                    $code = sprintf('MOV-%s-%06d', $year, $seq);
                    DB::table('movements')->where('id', $row->id)->update(['code' => $code]);
                }
            });

        foreach ($byYear as $year => $next) {
            Setting::setValue('movements.next_sequence.'.$year, $next + 1, 'int');
        }
    }
};
