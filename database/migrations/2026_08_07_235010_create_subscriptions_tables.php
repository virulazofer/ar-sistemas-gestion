<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('periodicity', 20); // monthly|quarterly|semiannual|annual
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3)->default('USD');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active'); // active|paused|cancelled|ended
            $table->unsignedTinyInteger('billing_day')->default(1); // día del mes preferido
            $table->date('next_generation_on')->nullable();
            $table->unsignedTinyInteger('reminder_days_before')->nullable(); // estructura futura de avisos
            $table->date('remind_on')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_generation_on']);
            $table->index(['status', 'remind_on']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('period_key', 32); // ej. 2026-09 (idempotencia)
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->foreignId('client_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->string('status', 20)->default('generated'); // generated|voided
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('subscriptions');
    }
};
