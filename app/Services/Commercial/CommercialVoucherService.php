<?php

namespace App\Services\Commercial;

use App\Enums\CommercialVoucherType;
use App\Enums\DocumentalStatus;
use App\Models\CommercialCharge;
use App\Models\CommercialVoucher;
use App\Models\Receipt;
use App\Models\Sale;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class CommercialVoucherService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{
     *   voucher_type: string,
     *   point_of_sale?: string|null,
     *   number?: string|null,
     *   issued_on?: string|null,
     *   currency_code?: string|null,
     *   amount?: string|float|int|null,
     *   net_amount?: string|null,
     *   vat_amount?: string|null,
     *   other_taxes?: string|null,
     *   fiscal_date?: string|null,
     *   fiscal_period?: string|null,
     *   notes?: string|null,
     *   set_documental_associated?: bool
     * }  $data
     */
    public function associate(Model $voucherable, array $data): CommercialVoucher
    {
        if (! in_array($voucherable::class, [CommercialCharge::class, Receipt::class, Sale::class], true)) {
            throw new InvalidArgumentException('Entidad no admite comprobante comercial.');
        }

        $type = CommercialVoucherType::from($data['voucher_type']);

        $voucher = CommercialVoucher::query()->create([
            'voucherable_type' => $voucherable::class,
            'voucherable_id' => $voucherable->getKey(),
            'voucher_type' => $type,
            'point_of_sale' => $data['point_of_sale'] ?? null,
            'number' => $data['number'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'currency_code' => isset($data['currency_code']) ? strtoupper((string) $data['currency_code']) : null,
            'amount' => $data['amount'] ?? null,
            'net_amount' => $data['net_amount'] ?? null,
            'vat_amount' => $data['vat_amount'] ?? null,
            'other_taxes' => $data['other_taxes'] ?? null,
            'fiscal_date' => $data['fiscal_date'] ?? null,
            'fiscal_period' => $data['fiscal_period'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => Auth::id() ?? throw new RuntimeException('Usuario requerido.'),
        ]);

        if ($data['set_documental_associated'] ?? true) {
            if ($voucherable instanceof CommercialCharge || $voucherable instanceof Receipt || $voucherable instanceof Sale) {
                $voucherable->update(['documental_status' => DocumentalStatus::Associated]);
            }
        }

        $this->audit->log('commercial_voucher_associated', $voucher, null, [
            'voucherable_type' => $voucherable::class,
            'voucherable_id' => $voucherable->getKey(),
            'voucher_type' => $type->value,
        ], 'Comprobante asociado');

        return $voucher;
    }
}
