<?php

namespace Database\Seeders;

use App\Enums\ChartAccountType;
use App\Models\ChartAccount;
use Illuminate\Database\Seeder;

/**
 * Árbol base §2 — cinco raíces protegidas + descendientes.
 * Idempotente por código contable visible (≠ id DB).
 */
class ChartAccountSeeder extends Seeder
{
    public function run(): void
    {
        // Raíz legado "Resultados": desactivar; si no tiene refs/hijos, eliminar para dejar exactamente 5 raíces.
        $legacyResult = ChartAccount::query()
            ->where('code', '6')
            ->whereNull('parent_id')
            ->first();
        if ($legacyResult) {
            $legacyResult->update(['is_active' => false, 'is_protected' => false, 'name' => 'Resultados (legado)']);
            $hasRefs = \App\Models\Movement::query()->where('chart_account_id', $legacyResult->id)->exists()
                || ChartAccount::query()->where('parent_id', $legacyResult->id)->exists()
                || \App\Models\Category::query()->where('chart_account_id', $legacyResult->id)->exists()
                || \App\Models\Subcategory::query()->where('chart_account_id', $legacyResult->id)->exists()
                || \App\Models\FinancialAccount::query()->where('chart_account_id', $legacyResult->id)->exists();
            if (! $hasRefs) {
                $legacyResult->delete();
            }
        }

        $tree = $this->definition();
        foreach ($tree as $node) {
            $this->upsertNode($node, null);
        }
    }

    /**
     * @param  array{code:string,name:string,type:string,sort?:int,help?:?string,suggested?:?string,children?:list<array>}  $node
     */
    private function upsertNode(array $node, ?int $parentId): ChartAccount
    {
        $isRoot = $parentId === null;
        $account = ChartAccount::query()->updateOrCreate(
            ['code' => $node['code']],
            [
                'name' => $node['name'],
                'type' => $node['type'],
                'parent_id' => $parentId,
                'is_active' => true,
                'is_protected' => $isRoot,
                'help_text' => $node['help'] ?? null,
                'suggested_scope' => $node['suggested'] ?? null,
                'sort_order' => $node['sort'] ?? 0,
            ]
        );

        foreach ($node['children'] ?? [] as $i => $child) {
            if (! isset($child['sort'])) {
                $child['sort'] = ($i + 1) * 10;
            }
            $this->upsertNode($child, $account->id);
        }

        return $account;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definition(): array
    {
        $asset = ChartAccountType::Asset->value;
        $liability = ChartAccountType::Liability->value;
        $equity = ChartAccountType::Equity->value;
        $income = ChartAccountType::Income->value;
        $expense = ChartAccountType::Expense->value;

        return [
            [
                'code' => '1', 'name' => 'ACTIVO', 'type' => $asset, 'sort' => 10,
                'help' => 'Bienes y derechos del negocio.',
                'children' => [
                    [
                        'code' => '1.1', 'name' => 'Disponibilidades', 'type' => $asset,
                        'children' => [
                            ['code' => '1.1.1', 'name' => 'Caja / Efectivo', 'type' => $asset],
                            ['code' => '1.1.2', 'name' => 'Bancos', 'type' => $asset],
                            ['code' => '1.1.3', 'name' => 'Billeteras virtuales', 'type' => $asset],
                        ],
                    ],
                    [
                        'code' => '1.2', 'name' => 'Créditos', 'type' => $asset,
                        'help' => 'Dinero que terceros le deben al negocio. Por ejemplo, saldos pendientes de clientes.',
                        'children' => [
                            ['code' => '1.2.1', 'name' => 'Clientes', 'type' => $asset],
                            ['code' => '1.2.2', 'name' => 'Anticipos', 'type' => $asset],
                            ['code' => '1.2.3', 'name' => 'Otros créditos', 'type' => $asset],
                        ],
                    ],
                    [
                        'code' => '1.3', 'name' => 'Créditos fiscales', 'type' => $asset,
                        'children' => [
                            ['code' => '1.3.1', 'name' => 'IVA Crédito Fiscal', 'type' => $asset],
                            ['code' => '1.3.2', 'name' => 'Retenciones sufridas', 'type' => $asset],
                            ['code' => '1.3.3', 'name' => 'Percepciones sufridas', 'type' => $asset],
                        ],
                    ],
                    [
                        'code' => '1.4', 'name' => 'Bienes de cambio', 'type' => $asset,
                        'children' => [
                            ['code' => '1.4.1', 'name' => 'Mercadería / Stock', 'type' => $asset],
                        ],
                    ],
                    [
                        'code' => '1.5', 'name' => 'Bienes de uso', 'type' => $asset,
                        'children' => [
                            ['code' => '1.5.1', 'name' => 'Equipamiento', 'type' => $asset],
                            ['code' => '1.5.2', 'name' => 'Muebles y útiles', 'type' => $asset],
                            ['code' => '1.5.3', 'name' => 'Instrumentos musicales', 'type' => $asset],
                            ['code' => '1.5.4', 'name' => 'Propiedades', 'type' => $asset],
                            ['code' => '1.5.5', 'name' => 'Vehículos', 'type' => $asset],
                            ['code' => '1.5.6', 'name' => 'Otros bienes de uso', 'type' => $asset],
                        ],
                    ],
                ],
            ],
            [
                'code' => '2', 'name' => 'PASIVO', 'type' => $liability, 'sort' => 20,
                'help' => 'Deudas y obligaciones.',
                'children' => [
                    ['code' => '2.1', 'name' => 'Tarjetas de crédito', 'type' => $liability],
                    ['code' => '2.2', 'name' => 'Proveedores', 'type' => $liability],
                    ['code' => '2.3', 'name' => 'Otras cuentas a pagar', 'type' => $liability],
                    [
                        'code' => '2.4', 'name' => 'Obligaciones fiscales', 'type' => $liability,
                        'children' => [
                            ['code' => '2.4.1', 'name' => 'IVA a pagar', 'type' => $liability],
                            ['code' => '2.4.2', 'name' => 'Ingresos Brutos', 'type' => $liability],
                            ['code' => '2.4.3', 'name' => 'Retenciones a depositar', 'type' => $liability],
                            ['code' => '2.4.4', 'name' => 'Otros impuestos', 'type' => $liability],
                        ],
                    ],
                ],
            ],
            [
                'code' => '3', 'name' => 'PATRIMONIO NETO', 'type' => $equity, 'sort' => 30,
                'help' => 'Representa la diferencia acumulada entre lo que el negocio posee y lo que debe, incluyendo capital, aportes y resultados.',
                'children' => [
                    ['code' => '3.1', 'name' => 'Capital', 'type' => $equity],
                    ['code' => '3.2', 'name' => 'Aportes / Retiros', 'type' => $equity],
                    ['code' => '3.3', 'name' => 'Resultados acumulados', 'type' => $equity],
                ],
            ],
            [
                'code' => '4', 'name' => 'INGRESOS', 'type' => $income, 'sort' => 40,
                'children' => [
                    [
                        'code' => '4.1', 'name' => 'Ventas', 'type' => $income, 'suggested' => 'professional',
                        'children' => [
                            ['code' => '4.1.1', 'name' => 'Equipos', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.1.2', 'name' => 'Componentes', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.1.3', 'name' => 'Otros productos', 'type' => $income, 'suggested' => 'professional'],
                        ],
                    ],
                    [
                        'code' => '4.2', 'name' => 'Servicios profesionales', 'type' => $income, 'suggested' => 'professional',
                        'children' => [
                            ['code' => '4.2.1', 'name' => 'Abonos', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.2.2', 'name' => 'Reparaciones', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.2.3', 'name' => 'Remotos', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.2.4', 'name' => 'Instalaciones', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.2.5', 'name' => 'Consultoría', 'type' => $income, 'suggested' => 'professional'],
                            ['code' => '4.2.6', 'name' => 'Otros servicios', 'type' => $income, 'suggested' => 'professional'],
                        ],
                    ],
                    [
                        'code' => '4.3', 'name' => 'Ingresos financieros', 'type' => $income, 'suggested' => 'financial',
                        'children' => [
                            ['code' => '4.3.1', 'name' => 'Intereses', 'type' => $income, 'suggested' => 'financial'],
                            ['code' => '4.3.2', 'name' => 'Rendimientos', 'type' => $income, 'suggested' => 'financial'],
                            ['code' => '4.3.3', 'name' => 'Otros', 'type' => $income, 'suggested' => 'financial'],
                        ],
                    ],
                ],
            ],
            [
                'code' => '5', 'name' => 'EGRESOS', 'type' => $expense, 'sort' => 50,
                'children' => [
                    [
                        'code' => '5.1', 'name' => 'Alimentación', 'type' => $expense,
                        'children' => [
                            ['code' => '5.1.1', 'name' => 'Supermercado', 'type' => $expense, 'suggested' => 'personal'],
                            ['code' => '5.1.2', 'name' => 'Comidas', 'type' => $expense],
                            ['code' => '5.1.3', 'name' => 'Carnicería', 'type' => $expense, 'suggested' => 'personal'],
                        ],
                    ],
                    [
                        'code' => '5.2', 'name' => 'Servicios', 'type' => $expense,
                        'children' => [
                            ['code' => '5.2.1', 'name' => 'Electricidad', 'type' => $expense],
                            ['code' => '5.2.2', 'name' => 'Gas', 'type' => $expense],
                            ['code' => '5.2.3', 'name' => 'Internet', 'type' => $expense],
                            ['code' => '5.2.4', 'name' => 'Telefonía', 'type' => $expense],
                            ['code' => '5.2.5', 'name' => 'Suscripciones', 'type' => $expense],
                        ],
                    ],
                    [
                        'code' => '5.3', 'name' => 'Automotor', 'type' => $expense,
                        'children' => [
                            ['code' => '5.3.1', 'name' => 'Combustible', 'type' => $expense],
                            ['code' => '5.3.2', 'name' => 'Peajes', 'type' => $expense],
                            ['code' => '5.3.3', 'name' => 'Estacionamiento', 'type' => $expense],
                            ['code' => '5.3.4', 'name' => 'Seguro', 'type' => $expense],
                            ['code' => '5.3.5', 'name' => 'Mantenimiento / Reparaciones', 'type' => $expense],
                            ['code' => '5.3.6', 'name' => 'Lavado / Limpieza', 'type' => $expense],
                        ],
                    ],
                    [
                        'code' => '5.4', 'name' => 'Gastos familiares', 'type' => $expense, 'suggested' => 'personal',
                        'children' => [
                            ['code' => '5.4.1', 'name' => 'Miranda', 'type' => $expense, 'suggested' => 'personal'],
                        ],
                    ],
                    ['code' => '5.5', 'name' => 'Muebles y útiles', 'type' => $expense],
                    ['code' => '5.6', 'name' => 'Impuestos y tasas', 'type' => $expense],
                    ['code' => '5.7', 'name' => 'Otros egresos', 'type' => $expense],
                ],
            ],
        ];
    }
}
