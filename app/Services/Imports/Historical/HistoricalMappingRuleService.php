<?php

namespace App\Services\Imports\Historical;

use App\Models\ImportMappingRule;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;

class HistoricalMappingRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Seed / ensure inequívocas rules approved for auto-apply.
     *
     * @return list<ImportMappingRule>
     */
    public function ensureUnequivocalRules(): array
    {
        $defs = [
            [
                'rule_type' => 'interpretation',
                'match_key' => 'cc_out_with_income',
                'match_value' => null,
                'action' => [
                    'downgrade_complex' => true,
                    'flag' => 'cc_combinado_ingreso',
                    'interpret_income' => true,
                    'interpret_cc_out' => true,
                    'no_duplicate_cash' => true,
                ],
                'notes' => 'CC OUT + ingreso financiero: cobro vinculado a caja, sin duplicar.',
            ],
            [
                'rule_type' => 'interpretation',
                'match_key' => 'card_liability_expense',
                'match_value' => null,
                'action' => [
                    'flag' => 'pago_tarjeta_posible',
                    'treat_as_liability_expense' => true,
                ],
                'notes' => 'Gasto en tarjeta aumenta pasivo; no es transferencia de pago de resumen.',
            ],
        ];

        $out = [];
        foreach ($defs as $def) {
            $rule = ImportMappingRule::query()->updateOrCreate(
                ['rule_type' => $def['rule_type'], 'match_key' => $def['match_key']],
                [
                    'match_value' => $def['match_value'],
                    'action' => $def['action'],
                    'is_active' => true,
                    'auto_apply' => true,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'notes' => $def['notes'],
                ]
            );
            $out[] = $rule;
        }

        return $out;
    }

    public function approveAccountAlias(string $excelAlias, array $accountDef, ?string $notes = null): ImportMappingRule
    {
        $rule = ImportMappingRule::query()->updateOrCreate(
            ['rule_type' => 'account_alias', 'match_key' => $excelAlias],
            [
                'match_value' => $accountDef['name'] ?? $excelAlias,
                'action' => $accountDef,
                'is_active' => true,
                'auto_apply' => true,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'notes' => $notes ?? "Mapear {$excelAlias} → ".($accountDef['name'] ?? ''),
            ]
        );

        // Materializar cuenta financiera para que el mapping exista más allá del preview
        app(AccountMappingService::class)->ensureAccountFromDef($excelAlias, $accountDef);

        $this->audit->log('import_mapping_rule_approved', $rule, null, $rule->toArray(), 'Regla de mapping aprobada');

        return $rule;
    }

    /**
     * @return list<ImportMappingRule>
     */
    public function activeRules(): array
    {
        return ImportMappingRule::query()
            ->where('is_active', true)
            ->where('auto_apply', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Resolve SubCuenta using approved account_alias rules then config.
     */
    public function resolveAccountAlias(string $subcuenta, AccountMappingService $accounts): ?array
    {
        $rule = ImportMappingRule::query()
            ->where('is_active', true)
            ->where('rule_type', 'account_alias')
            ->where('match_key', $subcuenta)
            ->first();

        if ($rule) {
            $rule->increment('times_applied');

            return array_merge($rule->action ?? [], ['_matched_alias' => $subcuenta, '_from_rule' => $rule->id]);
        }

        return $accounts->resolveAlias($subcuenta);
    }

    public function hasInterpretationRule(string $key): bool
    {
        return ImportMappingRule::query()
            ->where('is_active', true)
            ->where('auto_apply', true)
            ->where('rule_type', 'interpretation')
            ->where('match_key', $key)
            ->exists();
    }
}
