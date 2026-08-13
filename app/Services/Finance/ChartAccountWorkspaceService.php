<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\ChartAccountType;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Services\Clients\ClientCurrentAccountRankingService;
use App\Support\Money;
use App\Support\UiSemantics;
use Illuminate\Support\Collection;

/**
 * Workspace del Plan de cuentas: totales sin N+1, radiografía, vistas derivadas.
 */
class ChartAccountWorkspaceService
{
    public function __construct(
        private readonly ChartAccountAdminService $admin,
        private readonly ClientCurrentAccountRankingService $ccRanking,
    ) {}

    /**
     * Totales por cuenta (propios) en una sola query, luego rollup en memoria.
     *
     * @return array{
     *   by_id: array<int, array{own_ars:string,own_count:int,total_ars:string,count:int}>,
     *   roots: list<array<string,mixed>>
     * }
     */
    public function treeWithTotals(?string $from, ?string $to, ?string $scope = null): array
    {
        $accounts = ChartAccount::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'parent_id', 'is_active', 'is_protected', 'help_text', 'sort_order']);

        $own = $this->movementAggregates($from, $to, $scope);
        $byId = [];
        foreach ($accounts as $acc) {
            $o = $own[$acc->id] ?? ['own_ars' => '0.00', 'own_count' => 0];
            $byId[$acc->id] = [
                'own_ars' => $o['own_ars'],
                'own_count' => $o['own_count'],
                'total_ars' => $o['own_ars'],
                'count' => $o['own_count'],
            ];
        }

        // Rollup bottom-up by depth (parents after children via code length / BFS reverse).
        $childrenMap = [];
        foreach ($accounts as $acc) {
            $pid = $acc->parent_id;
            $childrenMap[$pid ?? 0][] = $acc->id;
        }
        $ordered = $this->postOrderIds($childrenMap, 0);
        foreach ($ordered as $id) {
            foreach ($childrenMap[$id] ?? [] as $childId) {
                $byId[$id]['total_ars'] = Money::add($byId[$id]['total_ars'], $byId[$childId]['total_ars']);
                $byId[$id]['count'] += $byId[$childId]['count'];
            }
        }

        $build = function (?int $parentId) use (&$build, $accounts, $byId): array {
            $nodes = [];
            foreach ($accounts->where('parent_id', $parentId) as $acc) {
                if (! $acc->is_active && ! $acc->is_protected) {
                    continue;
                }
                $t = $byId[$acc->id];
                $children = $build($acc->id);
                $nodes[] = [
                    'id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'type' => $acc->type instanceof \BackedEnum ? $acc->type->value : (string) $acc->type,
                    'type_label' => $acc->typeLabel(),
                    'help_text' => $acc->help_text,
                    'is_protected' => (bool) $acc->is_protected,
                    'is_leaf' => $children === [],
                    'total_ars' => $t['total_ars'],
                    'count' => $t['count'],
                    'own_ars' => $t['own_ars'],
                    'includes_descendants' => $children !== [],
                    'children' => $children,
                    'amount_mode' => UiSemantics::modeForChartType(
                        $acc->type instanceof ChartAccountType ? $acc->type : ChartAccountType::tryFrom((string) $acc->type)
                    ),
                ];
            }

            return $nodes;
        };

        return [
            'by_id' => $byId,
            'roots' => $build(null),
        ];
    }

    /**
     * @param  array<int|string, list<int>>  $childrenMap
     * @return list<int>
     */
    private function postOrderIds(array $childrenMap, int $rootKey): array
    {
        $out = [];
        $walk = function (int $id) use (&$walk, &$out, $childrenMap): void {
            foreach ($childrenMap[$id] ?? [] as $child) {
                $walk($child);
            }
            if ($id !== 0) {
                $out[] = $id;
            }
        };
        foreach ($childrenMap[$rootKey] ?? [] as $rootId) {
            $walk($rootId);
        }

        return $out;
    }

    /**
     * @return array<int, array{own_ars:string,own_count:int}>
     */
    private function movementAggregates(?string $from, ?string $to, ?string $scope): array
    {
        $q = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('type', [MovementType::Income->value, MovementType::Expense->value])
            ->whereNotNull('chart_account_id')
            ->when($from, fn ($qq) => $qq->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('movement_date', '<=', $to))
            ->when($scope, fn ($qq) => $qq->where('scope', $scope));

        $rows = $q->selectRaw('chart_account_id, COALESCE(SUM(amount_ars),0) as total_ars, COUNT(*) as cnt')
            ->groupBy('chart_account_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->chart_account_id] = [
                'own_ars' => Money::normalize((string) $r->total_ars),
                'own_count' => (int) $r->cnt,
            ];
        }

        return $out;
    }

    /**
     * Radiografía de las 5 raíces para el panel inicial.
     *
     * @param  list<array<string,mixed>>  $roots
     * @return list<array<string,mixed>>
     */
    public function radiography(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            $code = (string) $root['code'];
            $derived = $this->derivedSummaryForCode($code);
            $out[] = [
                'id' => $root['id'],
                'code' => $code,
                'name' => $root['name'],
                'type' => $root['type'],
                'help_text' => $root['help_text'] ?? null,
                'total_ars' => $derived['total_ars'] ?? $root['total_ars'],
                'count' => $root['count'],
                'display' => $derived['display'] ?? 'amount',
                'display_label' => $derived['display_label'] ?? null,
                'amount_mode' => $root['amount_mode'] ?? UiSemantics::MODE_RESULT,
                'children_preview' => collect($root['children'] ?? [])->take(6)->map(fn ($c) => [
                    'id' => $c['id'],
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'total_ars' => $c['total_ars'],
                    'display' => ($this->derivedSummaryForCode((string) $c['code'])['display'] ?? 'amount'),
                    'display_label' => ($this->derivedSummaryForCode((string) $c['code'])['display_label'] ?? null),
                ])->all(),
            ];
        }

        return $out;
    }

    /**
     * @return array{display:string,display_label:?string,total_ars:?string}
     */
    public function derivedSummaryForCode(string $code): array
    {
        return match (true) {
            $code === '1.4', str_starts_with($code, '1.4.') => [
                'display' => 'unavailable',
                'display_label' => 'Valuación de inventario no disponible',
                'total_ars' => null,
            ],
            $code === '3', str_starts_with($code, '3.') => [
                'display' => 'insufficient',
                'display_label' => 'Sin datos suficientes',
                'total_ars' => null,
            ],
            $code === '2.2' => $this->suppliersDerived(),
            $code === '2.1' => $this->cardsDerived(),
            $code === '1.2.1' => $this->clientsCreditDerived(),
            $code === '1.1', str_starts_with($code, '1.1.') => $this->disponibilidadesDerived($code),
            default => [
                'display' => 'amount',
                'display_label' => null,
                'total_ars' => null,
            ],
        };
    }

    /**
     * @return array{display:string,display_label:?string,total_ars:?string}
     */
    private function disponibilidadesDerived(string $code): array
    {
        $location = ChartAccount::query()->where('code', $code)->first();
        if (! $location) {
            return ['display' => 'insufficient', 'display_label' => 'Sin datos suficientes', 'total_ars' => null];
        }
        $ids = $location->selfAndDescendantIds();
        $fas = FinancialAccount::query()
            ->whereIn('chart_account_id', $ids)
            ->where('status', 'active')
            ->get(['id', 'cached_balance', 'type', 'is_liability']);

        if ($fas->isEmpty() && $code !== '1.1') {
            // Leaf location without linked FA: still show 0 as real empty cash bucket.
            return ['display' => 'amount', 'display_label' => null, 'total_ars' => '0.00'];
        }

        $sum = '0.00';
        foreach ($fas as $fa) {
            if ($fa->is_liability || ($fa->type?->value ?? $fa->type) === AccountType::CreditCard->value) {
                continue;
            }
            $sum = Money::add($sum, Money::normalize((string) $fa->cached_balance));
        }

        return ['display' => 'amount', 'display_label' => null, 'total_ars' => $sum];
    }

    /**
     * @return array{display:string,display_label:?string,total_ars:?string,ranking?:list<array<string,mixed>>}
     */
    private function clientsCreditDerived(): array
    {
        $ranking = $this->ccRanking->build(['filter' => ClientCurrentAccountRankingService::FILTER_OWING]);
        $total = Money::normalize((string) ($ranking['summary']['to_collect']['ARS'] ?? '0'));

        return [
            'display' => 'amount',
            'display_label' => null,
            'total_ars' => $total,
            'ranking' => array_slice($ranking['rows'], 0, 25),
            'summary' => $ranking['summary'],
        ];
    }

    /**
     * @return array{display:string,display_label:?string,total_ars:?string}
     */
    private function cardsDerived(): array
    {
        $fas = FinancialAccount::query()
            ->where(function ($q) {
                $q->where('type', AccountType::CreditCard->value)->orWhere('is_liability', true);
            })
            ->where('status', 'active')
            ->get(['id', 'name', 'cached_balance']);

        if ($fas->isEmpty()) {
            return ['display' => 'insufficient', 'display_label' => 'Sin datos suficientes', 'total_ars' => null];
        }

        $sum = '0.00';
        foreach ($fas as $fa) {
            // Deuda tarjeta: saldo de pasivo (mostrar magnitud positiva si el cache es negativo/deuda).
            $bal = Money::normalize((string) $fa->cached_balance);
            $sum = Money::add($sum, Money::isNegative($bal) ? Money::mul($bal, '-1') : $bal);
        }

        return ['display' => 'amount', 'display_label' => null, 'total_ars' => $sum, 'cards' => $fas];
    }

    /**
     * @return array{display:string,display_label:?string,total_ars:?string}
     */
    private function suppliersDerived(): array
    {
        // Sin módulo de CC proveedores fiable → no inventar $0.
        return [
            'display' => 'insufficient',
            'display_label' => 'Sin datos suficientes',
            'total_ars' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPayload(
        ChartAccount $account,
        ?string $from,
        ?string $to,
        ?string $scope,
        string $sortDir = 'desc',
        ?string $q = null,
        int $limit = 50,
    ): array {
        $ids = $account->selfAndDescendantIds();
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $base = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('chart_account_id', $ids)
            ->when($from, fn ($qq) => $qq->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('movement_date', '<=', $to))
            ->when($scope, fn ($qq) => $qq->where('scope', $scope))
            ->when($q, function ($qq) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
                $qq->where('description', 'like', $like);
            });

        $count = (clone $base)->count();
        $totalArs = Money::normalize((string) (clone $base)->sum('amount_ars'));

        $byScope = Movement::query()
            ->where('status', MovementStatus::Posted->value)
            ->whereIn('chart_account_id', $ids)
            ->when($from, fn ($qq) => $qq->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('movement_date', '<=', $to))
            ->selectRaw('scope, COUNT(*) as c, COALESCE(SUM(amount_ars),0) as total')
            ->groupBy('scope')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (string) ($r->scope instanceof \BackedEnum ? $r->scope->value : $r->scope) => [
                    'count' => (int) $r->c,
                    'total_ars' => Money::normalize((string) $r->total),
                ],
            ])
            ->all();

        $movements = (clone $base)
            ->with(['account:id,name', 'chartAccount:id,code,name'])
            ->orderBy('movement_date', $sortDir)
            ->orderBy('id', $sortDir)
            ->limit($limit)
            ->get();

        $children = $account->children()->orderBy('sort_order')->orderBy('code')->get();
        $distribution = $this->distribution($account, $from, $to, $scope);
        $derived = $this->derivedPanel($account);

        return [
            'usage' => $this->admin->usage($account),
            'path' => $account->pathLabel(),
            'count' => $count,
            'total_ars' => $derived['override_total'] ?? $totalArs,
            'by_scope' => $byScope,
            'movements' => $movements,
            'children' => $children,
            'includes_descendants' => ! $account->isLeaf(),
            'distribution' => $distribution,
            'derived' => $derived,
            'financial_accounts' => FinancialAccount::query()
                ->where('chart_account_id', $account->id)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'cached_balance', 'status']),
            'amount_mode' => UiSemantics::modeForChartType($account->type),
            'sort_dir' => $sortDir,
            'q' => $q,
        ];
    }

    /**
     * @return list<array{name:string,total_ars:string,percent:float,id:int}>
     */
    public function distribution(ChartAccount $account, ?string $from, ?string $to, ?string $scope): array
    {
        if ($account->isLeaf()) {
            return [];
        }

        $tree = $this->treeWithTotals($from, $to, $scope);
        $node = $this->findNode($tree['roots'], (int) $account->id);
        if (! $node) {
            return [];
        }
        $parentTotal = Money::normalize((string) $node['total_ars']);
        $parentFloat = (float) $parentTotal;
        $rows = [];
        foreach ($node['children'] as $child) {
            $amt = Money::normalize((string) $child['total_ars']);
            $pct = $parentFloat > 0 ? round(((float) $amt / $parentFloat) * 100, 1) : 0.0;
            $rows[] = [
                'id' => $child['id'],
                'name' => $child['name'],
                'total_ars' => $amt,
                'percent' => $pct,
            ];
        }
        usort($rows, fn ($a, $b) => (float) $b['total_ars'] <=> (float) $a['total_ars']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return array<string,mixed>|null
     */
    private function findNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $n) {
            if ((int) $n['id'] === $id) {
                return $n;
            }
            $found = $this->findNode($n['children'] ?? [], $id);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function derivedPanel(ChartAccount $account): array
    {
        $code = (string) $account->code;
        $summary = $this->derivedSummaryForCode($code);

        $panel = [
            'code' => $code,
            'display' => $summary['display'],
            'display_label' => $summary['display_label'],
            'override_total' => $summary['total_ars'],
            'kind' => 'default',
            'help' => $account->help_text,
        ];

        if ($code === '1.2.1') {
            $panel['kind'] = 'clients_cc';
            $panel['ranking'] = $summary['ranking'] ?? [];
            $panel['help'] = $panel['help'] ?: 'Dinero que terceros deben. Por ejemplo, saldos pendientes de clientes.';
        } elseif ($code === '1.4' || str_starts_with($code, '1.4.')) {
            $panel['kind'] = 'inventory';
            $panel['fifo_ready'] = true;
        } elseif ($code === '1.1' || str_starts_with($code, '1.1.')) {
            $panel['kind'] = 'disponibilidades';
            $panel['accounts'] = FinancialAccount::query()
                ->whereIn('chart_account_id', $account->selfAndDescendantIds())
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'cached_balance', 'chart_account_id']);
        } elseif ($code === '2.1') {
            $panel['kind'] = 'cards';
            $panel['cards'] = $summary['cards'] ?? collect();
        } elseif ($code === '2.2') {
            $panel['kind'] = 'suppliers';
        } elseif ($code === '3' || str_starts_with($code, '3.')) {
            $panel['kind'] = 'equity';
            $panel['help'] = 'El Patrimonio Neto representa, de forma simplificada, la diferencia entre lo que se posee y lo que se debe, junto con los aportes y resultados acumulados.';
        } elseif ($code === '1') {
            $panel['help'] = $panel['help'] ?: 'Bienes, dinero y derechos con valor económico.';
        } elseif ($code === '2') {
            $panel['help'] = $panel['help'] ?: 'Deudas y obligaciones pendientes.';
        }

        return $panel;
    }
}
