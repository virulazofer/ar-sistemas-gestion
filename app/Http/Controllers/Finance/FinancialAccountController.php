<?php

namespace App\Http\Controllers\Finance;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Rules\CbuCvu;
use App\Rules\Cuit;
use App\Rules\Luhn;
use App\Services\AuditLogger;
use App\Services\Finance\BalanceService;
use App\Services\Finance\FinancialAccountChartLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinancialAccountController extends Controller
{
    public function __construct(
        private readonly BalanceService $balances,
        private readonly FinancialAccountChartLinker $chartLinker,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $showInactive = $request->boolean('inactive');

        $accounts = FinancialAccount::query()
            ->with('currency')
            ->when(! $showInactive, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get();

        foreach ($accounts as $account) {
            $account->setAttribute('computed_balance', $this->balances->computeAccountBalance($account->id));
            $account->setAttribute('balance_reliable', $account->currency_id !== null);
        }

        return view('finance.accounts.index', compact('accounts', 'showInactive'));
    }

    public function create(): View
    {
        return view('finance.accounts.create', [
            'currencies' => Currency::query()->active()->orderBy('code')->get(),
            'types' => AccountType::cases(),
            'paymentAccounts' => FinancialAccount::query()
                ->where('status', 'active')
                ->where('type', '!=', AccountType::CreditCard->value)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->filled('card_pan_full')) {
            $this->audit->log('account_card_pan_attempt', null, null, [
                'name' => $request->input('name'),
                'note' => 'Se intentó cargar PAN completo; no se almacena. Usar solo last4.',
            ], 'Intento de cargar PAN completo en cuenta tarjeta');
        }

        $data = $this->validated($request);
        $type = AccountType::from($data['type']);
        $data['is_liability'] = $type->isLiability();
        $data['cached_balance'] = 0;

        $isKnownType = in_array($type->value, [
            AccountType::Cash->value,
            AccountType::Bank->value,
            AccountType::Wallet->value,
            AccountType::CreditCard->value,
        ], true);
        if ($isKnownType) {
            unset($data['chart_account_id']);
        }

        $account = FinancialAccount::query()->create($data);
        if ($isKnownType || empty($account->chart_account_id)) {
            $this->chartLinker->link($account, force: true);
        }

        $this->audit->log('account_created', $account, null, $account->fresh()->only([
            'name', 'type', 'currency_id', 'status', 'alias', 'institution', 'holder_name',
            'cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'chart_account_id',
        ]), 'Cuenta creada');

        return redirect()->route('accounts.index')->with('status', 'Cuenta creada.');
    }

    public function edit(FinancialAccount $financial_account): View
    {
        $derivedCode = $this->chartLinker->locationCodeFor($financial_account);
        $unmapped = $derivedCode === null;

        return view('finance.accounts.edit', [
            'account' => $financial_account,
            'currencies' => Currency::query()->active()->orderBy('code')->get(),
            'types' => AccountType::cases(),
            'derivedCode' => $derivedCode,
            'unmapped' => $unmapped,
            'paymentAccounts' => FinancialAccount::query()
                ->where('status', 'active')
                ->where('type', '!=', AccountType::CreditCard->value)
                ->where('id', '!=', $financial_account->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, FinancialAccount $financial_account): RedirectResponse
    {
        if ($request->filled('card_pan_full')) {
            $this->audit->log('account_card_pan_attempt', $financial_account, null, [
                'account_id' => $financial_account->id,
                'note' => 'Se intentó cargar PAN completo; no se almacena.',
            ], 'Intento de cargar PAN completo en cuenta tarjeta');
        }

        $data = $this->validated($request, updating: true);

        $old = $financial_account->only([
            'name', 'type', 'status', 'description', 'alias', 'institution', 'holder_name',
            'cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'card_holder', 'chart_account_id',
            'default_payment_financial_account_id',
        ]);
        $previousType = $financial_account->type instanceof AccountType
            ? $financial_account->type->value
            : (string) $financial_account->type;
        $type = AccountType::from($data['type']);
        $data['is_liability'] = $type->isLiability();
        $typeChanged = $previousType !== $type->value;

        // Moneda inmutable en edición.
        unset($data['currency_id']);
        $isKnownType = in_array($type->value, [
            AccountType::Cash->value,
            AccountType::Bank->value,
            AccountType::Wallet->value,
            AccountType::CreditCard->value,
        ], true);
        if ($isKnownType) {
            unset($data['chart_account_id']);
        }

        $balanceBefore = (string) $financial_account->cached_balance;
        $movementsBefore = $financial_account->movements()->count();

        $financial_account->update($data);
        $fresh = $financial_account->fresh();

        // Tipos normales: ubicación siempre derivada del tipo (sin nodos por FA).
        if ($isKnownType || $typeChanged || ! $fresh->chart_account_id) {
            $this->chartLinker->link($fresh, force: true);
            $fresh = $fresh->fresh();
        }

        if ($typeChanged) {
            $this->audit->log('account_type_changed', $fresh, [
                'type' => $previousType,
                'chart_account_id' => $old['chart_account_id'] ?? null,
            ], [
                'type' => $type->value,
                'chart_account_id' => $fresh->chart_account_id,
                'derived_code' => $this->chartLinker->locationCodeFor($fresh),
            ], 'Cambio de tipo de cuenta financiera (ubicación derivada actualizada)');
        }

        $this->audit->log('account_updated', $financial_account, $old, $fresh->only([
            'name', 'type', 'status', 'description', 'alias', 'institution', 'holder_name',
            'cbu_cvu', 'cuit', 'card_last4', 'card_brand', 'card_holder', 'chart_account_id',
            'default_payment_financial_account_id',
        ]), 'Cuenta actualizada');

        // Integridad: no tocar movimientos ni saldos en este flujo.
        if ((string) $fresh->cached_balance !== $balanceBefore
            || $fresh->movements()->count() !== $movementsBefore) {
            report(new \RuntimeException('FA update alteró saldo/movimientos inesperadamente'));
        }

        return redirect()->route('accounts.index')->with('status', 'Cuenta actualizada.');
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $type = (string) $request->input('type');
        $isCard = $type === AccountType::CreditCard->value;
        $isBankish = in_array($type, [AccountType::Bank->value, AccountType::Wallet->value], true);
        $isKnown = in_array($type, [
            AccountType::Cash->value,
            AccountType::Bank->value,
            AccountType::Wallet->value,
            AccountType::CreditCard->value,
        ], true);

        // Rechazar cualquier intento de enviar CVV/CVC
        foreach (['cvv', 'cvc', 'card_cvv', 'card_cvc', 'security_code'] as $forbidden) {
            if ($request->filled($forbidden)) {
                abort(422, 'CVV/CVC no está permitido.');
            }
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'alias' => ['nullable', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:120'],
            'holder_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'external_identifier' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];

        if (! $updating) {
            $rules['currency_id'] = ['required', 'exists:currencies,id'];
        }

        // Mapeo manual solo para tipo excepcional / no reconocido.
        if ($isKnown) {
            $rules['chart_account_id'] = ['nullable', 'prohibited'];
        } else {
            $rules['chart_account_id'] = ['nullable', 'integer', 'exists:chart_accounts,id'];
        }

        if ($isBankish) {
            $rules['cbu_cvu'] = ['nullable', 'string', 'max:32', new CbuCvu];
            $rules['cuit'] = ['nullable', 'string', 'max:20', new Cuit];
        } else {
            // ConvertEmptyStringsToNull deja null: debe ser nullable para no disparar validation.string.
            $rules['cbu_cvu'] = ['nullable', 'prohibited'];
            $rules['cuit'] = ['nullable', 'prohibited'];
        }

        if ($isCard) {
            $rules['card_number'] = ['nullable', 'string', 'max:32', new Luhn];
            $rules['card_last4'] = ['nullable', 'digits:4'];
            $rules['card_brand'] = ['nullable', 'string', 'max:40'];
            $rules['card_holder'] = ['nullable', 'string', 'max:120'];
            $rules['card_expiry_month'] = ['required', 'integer', 'min:1', 'max:12'];
            $rules['card_expiry_year'] = ['required', 'integer', 'min:2020', 'max:2100'];
            $rules['card_issue_date'] = ['nullable', 'date'];
            $rules['default_payment_financial_account_id'] = [
                'nullable',
                'integer',
                'exists:financial_accounts,id',
                Rule::notIn([(int) $request->route('financial_account')?->id]),
            ];
            $rules['card_pan_full'] = ['nullable', 'string', 'max:32'];
        } else {
            // Campos de tarjeta siempre llegan vacíos desde el formulario tipado;
            // null tras ConvertEmptyStringsToNull no debe romper con validation.string.
            $rules['card_number'] = ['nullable', 'prohibited'];
            $rules['card_last4'] = ['nullable', 'prohibited'];
            $rules['card_brand'] = ['nullable', 'prohibited'];
            $rules['card_holder'] = ['nullable', 'prohibited'];
            $rules['card_expiry_month'] = ['nullable', 'prohibited'];
            $rules['card_expiry_year'] = ['nullable', 'prohibited'];
            $rules['card_issue_date'] = ['nullable', 'prohibited'];
            $rules['default_payment_financial_account_id'] = ['nullable', 'prohibited'];
            $rules['card_pan_full'] = ['nullable', 'prohibited'];
        }

        $data = $request->validate($rules, [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'digits' => 'El campo :attribute debe tener :digits dígitos.',
            'prohibited' => 'El campo :attribute no corresponde a este tipo de cuenta.',
            'in' => 'El :attribute seleccionado no es válido.',
            'exists' => 'El :attribute seleccionado no es válido.',
            'max.string' => 'El campo :attribute no debe tener más de :max caracteres.',
            'date' => 'El campo :attribute no es una fecha válida.',
        ], [
            'name' => 'nombre',
            'type' => 'tipo',
            'alias' => 'alias',
            'institution' => 'institución',
            'holder_name' => 'titular',
            'description' => 'descripción',
            'external_identifier' => 'identificador / número de cuenta',
            'status' => 'estado',
            'currency_id' => 'moneda',
            'cbu_cvu' => 'CBU/CVU',
            'cuit' => 'CUIT',
            'card_number' => 'número de tarjeta',
            'card_last4' => 'últimos 4',
            'card_brand' => 'marca',
            'card_holder' => 'titular de tarjeta',
            'card_expiry_month' => 'mes de vencimiento',
            'card_expiry_year' => 'año de vencimiento',
            'card_issue_date' => 'fecha de emisión',
            'default_payment_financial_account_id' => 'cuenta habitual de pago',
            'chart_account_id' => 'ubicación contable',
        ]);

        $data['cbu_cvu'] = CbuCvu::normalize($data['cbu_cvu'] ?? null);
        $data['cuit'] = Cuit::normalize($data['cuit'] ?? null);

        if ($isCard) {
            $fromNumber = Luhn::last4($data['card_number'] ?? null);
            if ($fromNumber) {
                $data['card_last4'] = $fromNumber;
            }
            if (empty($data['card_holder']) && ! empty($data['holder_name'])) {
                $data['card_holder'] = $data['holder_name'];
            } elseif (! empty($data['card_holder']) && empty($data['holder_name'])) {
                $data['holder_name'] = $data['card_holder'];
            }
            $month = (int) $data['card_expiry_month'];
            $year = (int) $data['card_expiry_year'];
            $end = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
            if ($end->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'card_expiry_month' => 'La tarjeta está vencida.',
                ]);
            }
        } else {
            $data['card_last4'] = null;
            $data['card_brand'] = null;
            $data['card_holder'] = null;
            $data['card_expiry_month'] = null;
            $data['card_expiry_year'] = null;
            $data['card_issue_date'] = null;
            $data['default_payment_financial_account_id'] = null;
            if (! $isBankish) {
                $data['cbu_cvu'] = null;
                $data['cuit'] = null;
            }
        }

        unset($data['card_pan_full'], $data['card_number'], $data['chart_account_id']);

        if (! $isKnown) {
            // Se permite guardar "other"; la UI mostrará alerta de ubicación.
        }

        return $data;
    }
}
