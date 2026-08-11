<?php

namespace App\Console\Commands;

use App\Enums\CommercialChargeType;
use App\Enums\CommercialItemType;
use App\Models\Client;
use App\Models\CommercialCharge;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\User;
use App\Services\Clients\ClientLedgerService;
use App\Services\Commercial\CommercialChargeService;
use App\Services\Commercial\ReceiptService;
use App\Services\Sales\SaleService;
use App\Support\UiSemantics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Smoke 11F-3 en staging con datos claramente marcados TEST; revierte al final.
 */
class Stage11F3SmokeCommand extends Command
{
    protected $signature = 'staging:smoke-11f3 {--cleanup-only : Solo anular/limpiar restos TEST 11F-3}';

    protected $description = 'Smoke funcional 11F-3 (DAASA/Cintas/contado) con cleanup';

    public function handle(
        CommercialChargeService $charges,
        ReceiptService $receipts,
        SaleService $sales,
        ClientLedgerService $ledger,
    ): int {
        $admin = User::query()->orderBy('id')->first();
        if (! $admin) {
            $this->error('Sin usuario admin.');

            return self::FAILURE;
        }
        Auth::login($admin);

        if ($this->option('cleanup-only')) {
            $this->cleanupByMarker('TEST 11F-3', $sales, $receipts, $charges);
            $this->info('Cleanup-only OK.');

            return self::SUCCESS;
        }

        $daasa = Client::query()->where('name', 'like', '%DAASA%')->orderBy('id')->first();
        $cintas = Client::query()->where('name', 'like', '%Cintas%')->orderBy('id')->first();
        if (! $daasa || ! $cintas) {
            $this->error('No se encontraron clientes DAASA/Cintas en staging.');

            return self::FAILURE;
        }

        $patagonia = FinancialAccount::query()
            ->where(function ($q) {
                $q->where('name', 'like', '%Patagonia%')
                    ->orWhere('name', 'like', '%patagonia%');
            })
            ->first();
        if (! $patagonia) {
            $patagonia = FinancialAccount::query()->whereHas('currency', fn ($q) => $q->where('code', 'ARS'))->active()->first();
        }
        if (! $patagonia) {
            $this->error('Sin cuenta ARS receptora.');

            return self::FAILURE;
        }

        $marker = 'TEST 11F-3 '.now()->format('YmdHis');
        $currency = $patagonia->currency?->code ?? 'ARS';
        $this->info("Marker: {$marker}");
        $this->line('DAASA='.$daasa->labelWithCode().' Cintas='.$cintas->labelWithCode().' Cuenta='.$patagonia->name);

        $balDaasaBefore = $ledger->balanceFor($daasa, $currency);

        $charge = $charges->create([
            'client_id' => $daasa->id,
            'charge_type' => CommercialChargeType::Subscription->value,
            'concept' => $marker.' Abono de prueba DAASA',
            'amount' => '1000.00',
            'currency_code' => $currency,
            'notes' => $marker,
        ]);
        $mid = $ledger->balanceFor($daasa, $currency);
        $this->info('CASO1 cargo #'.$charge->number.': CC '.$balDaasaBefore.' → '.$mid);

        $r1 = $receipts->create([
            'client_id' => $daasa->id,
            'financial_account_id' => $patagonia->id,
            'amount' => '1000.00',
            'concept' => $marker.' Cobro DAASA',
            'application_mode' => 'auto',
            'notes' => $marker,
        ])['receipt'];
        $after1 = $ledger->balanceFor($daasa, $currency);
        $this->info('CASO1 cobro #'.$r1->number.' CC → '.$after1);

        $r2 = $receipts->create([
            'client_id' => $daasa->id,
            'financial_account_id' => $patagonia->id,
            'amount' => '500.00',
            'concept' => $marker.' Pago a cuenta',
            'application_mode' => 'auto',
            'insufficient_option' => ReceiptService::OPTION_ON_ACCOUNT,
            'notes' => $marker,
        ])['receipt'];
        $favor = $ledger->balanceFor($daasa, $currency);
        $display = UiSemantics::clientCcDisplayBalance($favor);
        $this->info('CASO2 pago a cuenta #'.$r2->number.': ledger='.$favor.' display='.$display);

        $c2 = $charges->create([
            'client_id' => $daasa->id,
            'charge_type' => CommercialChargeType::Service->value,
            'concept' => $marker.' Cargo consume favor',
            'amount' => '200.00',
            'currency_code' => $currency,
            'apply_available_credit' => true,
            'notes' => $marker,
        ]);
        $this->info('CASO2 cargo #'.$c2->number.' status='.$c2->fresh()->status->value.' CC='.$ledger->balanceFor($daasa, $currency));

        $saleCredit = $sales->create([
            'client_id' => $cintas->id,
            'currency_code' => $currency,
            'sold_on' => now()->toDateString(),
            'notes' => $marker,
            'items' => [[
                'item_type' => CommercialItemType::Service->value,
                'description' => $marker.' Venta crédito Cintas',
                'quantity' => '1',
                'unit_price' => '750',
                'currency_code' => $currency,
            ]],
        ]);
        $incomeBefore = Movement::query()->where('type', 'income')->where('status', 'posted')->count();
        $sales->confirm($saleCredit, ['payment_mode' => Sale::MODE_CREDIT]);
        $incomeAfterCredit = Movement::query()->where('type', 'income')->where('status', 'posted')->count();
        $this->info('CASO3 crédito Cintas #'.$saleCredit->fresh()->number.': CC='.$ledger->balanceFor($cintas, $currency).' income_delta='.($incomeAfterCredit - $incomeBefore));

        $saleCash = $sales->create([
            'client_id' => $cintas->id,
            'currency_code' => $currency,
            'sold_on' => now()->toDateString(),
            'notes' => $marker,
            'items' => [[
                'item_type' => CommercialItemType::Service->value,
                'description' => $marker.' Venta contado',
                'quantity' => '1',
                'unit_price' => '300',
                'currency_code' => $currency,
            ]],
        ]);
        $ccBeforeCash = $ledger->balanceFor($cintas, $currency);
        $sales->confirm($saleCash, [
            'payment_mode' => Sale::MODE_CASH,
            'financial_account_id' => $patagonia->id,
        ]);
        $ccAfterCash = $ledger->balanceFor($cintas, $currency);
        $this->info('CASO4 contado #'.$saleCash->fresh()->number.': CC '.$ccBeforeCash.' → '.$ccAfterCash);

        $this->warn('Revirtiendo datos TEST...');
        $this->cleanupByMarker($marker, $sales, $receipts, $charges);
        $this->info('Smoke 11F-3 OK. Marker limpiado: '.$marker);

        return self::SUCCESS;
    }

    private function cleanupByMarker(string $marker, SaleService $sales, ReceiptService $receipts, CommercialChargeService $charges): void
    {
        foreach (Sale::query()->where('notes', 'like', $marker.'%')->where('status', 'confirmed')->orderByDesc('id')->get() as $sale) {
            try {
                $sales->void($sale, 'Cleanup '.$marker);
                $this->line('void sale '.$sale->number);
            } catch (\Throwable $e) {
                $this->warn('Void sale '.$sale->number.': '.$e->getMessage());
            }
        }

        foreach (Receipt::query()->where(function ($q) use ($marker) {
            $q->where('notes', 'like', $marker.'%')->orWhere('concept', 'like', $marker.'%');
        })->orderByDesc('id')->get() as $receipt) {
            if ($receipt->isPosted()) {
                try {
                    $receipts->void($receipt, 'Cleanup '.$marker);
                    $this->line('void receipt '.$receipt->number);
                } catch (\Throwable $e) {
                    $this->warn('Void receipt '.$receipt->number.': '.$e->getMessage());
                }
            }
        }

        foreach (CommercialCharge::query()->where(function ($q) use ($marker) {
            $q->where('notes', 'like', $marker.'%')->orWhere('concept', 'like', $marker.'%');
        })->orderByDesc('id')->get() as $charge) {
            if ($charge->status->value !== 'voided') {
                try {
                    $charges->void($charge, 'Cleanup '.$marker);
                    $this->line('void charge '.$charge->number);
                } catch (\Throwable $e) {
                    $this->warn('Void charge '.$charge->number.': '.$e->getMessage());
                }
            }
        }
    }
}
