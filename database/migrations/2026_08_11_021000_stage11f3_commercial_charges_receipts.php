<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('code')->nullable()->unique()->after('id');
        });

        // Backfill códigos permanentes por orden de alta (id).
        $clients = DB::table('clients')->orderBy('id')->get(['id']);
        $n = 1;
        foreach ($clients as $client) {
            DB::table('clients')->where('id', $client->id)->update(['code' => $n]);
            $n++;
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'clients.next_code'],
                [
                    'value' => (string) $n,
                    'type' => 'int',
                    'group' => 'clients',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        Schema::create('commercial_charges', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->unsignedInteger('sequence');
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('charge_type', 40);
            $table->string('concept');
            $table->date('charged_on');
            $table->date('due_on')->nullable();
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_applied', 18, 2)->default(0);
            $table->decimal('amount_open', 18, 2);
            $table->string('scope', 20)->default('professional'); // professional|personal
            $table->string('status', 20)->default('pending'); // pending|partial|collected|voided
            $table->string('documental_status', 30)->default('none');
            $table->text('notes')->nullable();
            $table->foreignId('client_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('subscription_period_id')->nullable()->constrained('subscription_periods')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'currency_code', 'status']);
            $table->index(['charged_on', 'status']);
            $table->index(['documental_status', 'charged_on']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->unsignedInteger('sequence');
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->date('received_on');
            $table->string('currency_code', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_applied', 18, 2)->default(0);
            $table->decimal('amount_on_account', 18, 2)->default(0);
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('financial_movement_id')->nullable()->constrained('movements')->nullOnDelete();
            $table->foreignId('client_ledger_entry_id')->nullable()->constrained('client_ledger_entries')->nullOnDelete();
            $table->string('application_mode', 20)->default('auto'); // auto|manual
            $table->string('insufficient_option', 20)->nullable(); // create_charge|on_account|null
            $table->string('concept')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->string('documental_status', 30)->default('none');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['received_on', 'status']);
            $table->index(['documental_status', 'received_on']);
        });

        Schema::create('receipt_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts')->restrictOnDelete();
            $table->foreignId('commercial_charge_id')->constrained('commercial_charges')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['commercial_charge_id', 'status']);
            $table->index(['receipt_id', 'status']);
        });

        Schema::create('commercial_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucherable_type');
            $table->unsignedBigInteger('voucherable_id');
            $table->string('voucher_type', 30); // invoice|credit_note|debit_note|other
            $table->string('point_of_sale', 8)->nullable();
            $table->string('number', 40)->nullable();
            $table->date('issued_on')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            // Preparación capa impositiva futura (nullable)
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->decimal('vat_amount', 18, 2)->nullable();
            $table->decimal('other_taxes', 18, 2)->nullable();
            $table->date('fiscal_date')->nullable();
            $table->string('fiscal_period', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['voucherable_type', 'voucherable_id']);
            $table->index(['voucher_type', 'issued_on']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('documental_status', 30)->default('none')->after('notes');
            $table->foreignId('commercial_charge_id')->nullable()->after('charge_ledger_entry_id')
                ->constrained('commercial_charges')->nullOnDelete();
            $table->decimal('amount_paid_on_confirm', 18, 2)->nullable()->after('payment_mode');
        });

        Schema::table('subscription_periods', function (Blueprint $table) {
            $table->foreignId('commercial_charge_id')->nullable()->after('client_ledger_entry_id')
                ->constrained('commercial_charges')->nullOnDelete();
            $table->string('documental_status', 30)->default('none')->after('status');
        });

        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->foreignId('commercial_charge_id')->nullable()->after('document_id')
                ->constrained('commercial_charges')->nullOnDelete();
            $table->foreignId('receipt_id')->nullable()->after('commercial_charge_id')
                ->constrained('receipts')->nullOnDelete();
            $table->string('regularization_kind', 40)->nullable()->after('reason');
            $table->unsignedBigInteger('related_ledger_entry_id')->nullable()->after('regularization_kind');
        });

        // Backfill cargos comerciales desde ledger Charge posted (sin duplicar finanzas).
        if (Schema::hasTable('commercial_charges')) {
            $charges = DB::table('client_ledger_entries')
                ->where('type', 'charge')
                ->where('status', 'posted')
                ->orderBy('id')
                ->get();

            $seq = 1;
            foreach ($charges as $entry) {
                $currency = DB::table('currencies')->where('id', $entry->currency_id)->value('code') ?? 'ARS';
                $amount = (string) $entry->amount;
                $number = sprintf('CG-%06d', $seq);
                $chargeId = DB::table('commercial_charges')->insertGetId([
                    'number' => $number,
                    'sequence' => $seq,
                    'client_id' => $entry->client_id,
                    'charge_type' => $entry->subscription_id ? 'subscription' : ($entry->sale_id ? 'sale' : ($entry->work_order_id ? 'repair' : 'other')),
                    'concept' => $entry->description ?: ('Cargo histórico #'.$entry->id),
                    'charged_on' => $entry->entry_date,
                    'due_on' => null,
                    'currency_code' => $currency,
                    'amount' => $amount,
                    'amount_applied' => '0.00',
                    'amount_open' => $amount,
                    'scope' => 'professional',
                    'status' => 'pending',
                    'documental_status' => 'none',
                    'notes' => 'Backfill 11F-3 desde ledger #'.$entry->id,
                    'client_ledger_entry_id' => $entry->id,
                    'sale_id' => $entry->sale_id,
                    'subscription_id' => $entry->subscription_id,
                    'subscription_period_id' => null,
                    'work_order_id' => $entry->work_order_id,
                    'user_id' => $entry->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('client_ledger_entries')->where('id', $entry->id)->update([
                    'commercial_charge_id' => $chargeId,
                ]);
                if ($entry->sale_id) {
                    DB::table('sales')->where('id', $entry->sale_id)->update([
                        'commercial_charge_id' => $chargeId,
                    ]);
                }
                $seq++;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'commercial_charges.next_sequence'],
                ['value' => (string) $seq, 'type' => 'int', 'group' => 'commercial', 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('settings')->updateOrInsert(
                ['key' => 'receipts.next_sequence'],
                ['value' => '1', 'type' => 'int', 'group' => 'commercial', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('client_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipt_id');
            $table->dropConstrainedForeignId('commercial_charge_id');
            $table->dropColumn(['regularization_kind', 'related_ledger_entry_id']);
        });

        Schema::table('subscription_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commercial_charge_id');
            $table->dropColumn('documental_status');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commercial_charge_id');
            $table->dropColumn(['documental_status', 'amount_paid_on_confirm']);
        });

        Schema::dropIfExists('commercial_vouchers');
        Schema::dropIfExists('receipt_applications');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('commercial_charges');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
