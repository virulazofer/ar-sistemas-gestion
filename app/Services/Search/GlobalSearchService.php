<?php

namespace App\Services\Search;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class GlobalSearchService
{
    public const MATCH_EXACT_START = 0;

    public const MATCH_WORD_START = 1;

    public const MATCH_PARTIAL = 2;

    public const MATCH_NONE = 99;

    public const GROUP_ORDER = [
        'navigation',
        'actions',
        'clients',
        'suppliers',
        'products',
        'equipment',
        'work_orders',
        'quotations',
        'sales',
    ];

    public const GROUP_LABELS = [
        'navigation' => 'Navegación',
        'actions' => 'Acciones',
        'clients' => 'Clientes',
        'suppliers' => 'Proveedores',
        'products' => 'Productos',
        'equipment' => 'Equipos',
        'work_orders' => 'Órdenes de trabajo',
        'quotations' => 'Presupuestos',
        'sales' => 'Ventas',
    ];

    /** Máximo de filas a rankear en memoria por entidad (volumen actual). */
    private const RANK_CAP = 500;

    /**
     * Preview Command Palette / JSON.
     *
     * @return array{
     *   navigation: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   clients: list<array<string, mixed>>,
     *   suppliers: list<array<string, mixed>>,
     *   products: list<array<string, mixed>>,
     *   equipment: list<array<string, mixed>>,
     *   work_orders: list<array<string, mixed>>,
     *   quotations: list<array<string, mixed>>,
     *   sales: list<array<string, mixed>>,
     *   meta: array{has_more: array<string, bool>, totals: array<string, int>, total: int}
     * }
     */
    public function search(string $q, int $limit = 8, ?User $user = null): array
    {
        $q = trim($q);
        $limit = max(1, min(25, $limit));
        $user ??= Auth::user();
        $out = $this->emptyResult();

        if ($q === '' || mb_strlen($q) < 1) {
            return $out;
        }

        $needle = mb_strtolower($q);
        $totals = [];
        $hasMore = [];

        foreach (self::GROUP_ORDER as $group) {
            if (! $this->canSearchGroup($user, $group)) {
                $out[$group] = [];
                $totals[$group] = 0;
                $hasMore[$group] = false;

                continue;
            }

            [$items, $total] = $this->searchGroup($group, $needle, 0, $limit, $user);
            $out[$group] = $items;
            $totals[$group] = $total;
            $hasMore[$group] = $total > $limit;
        }

        $out['meta'] = [
            'has_more' => $hasMore,
            'totals' => $totals,
            'total' => array_sum($totals),
        ];

        return $out;
    }

    /**
     * Página completa RESULTADOS DE BÚSQUEDA con paginación backend.
     *
     * @return array{
     *   q: string,
     *   type: string,
     *   totals: array<string, int>,
     *   total: int,
     *   items: list<array<string, mixed>>,
     *   groups: array<string, list<array<string, mixed>>>,
     *   paginator: LengthAwarePaginator
     * }
     */
    public function searchPage(string $q, string $type, int $page, int $perPage, ?User $user = null): array
    {
        $q = trim($q);
        $user ??= Auth::user();
        $type = $this->normalizeType($type);
        $page = max(1, $page);
        $perPage = max(5, min(50, $perPage));

        $totals = $this->emptyTotals();
        $items = [];
        $total = 0;

        if ($q === '' || mb_strlen($q) < 1) {
            $paginator = new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]);

            return [
                'q' => $q,
                'type' => $type,
                'totals' => $totals,
                'total' => 0,
                'items' => [],
                'groups' => $this->emptyGroups(),
                'paginator' => $paginator->appends(request()->query()),
            ];
        }

        $needle = mb_strtolower($q);

        foreach (self::GROUP_ORDER as $group) {
            if (! $this->canSearchGroup($user, $group)) {
                $totals[$group] = 0;

                continue;
            }
            $totals[$group] = $this->countGroup($group, $needle, $user);
        }

        if ($type === 'all') {
            $total = array_sum($totals);
            $offset = ($page - 1) * $perPage;
            $remaining = $perPage;
            $skip = $offset;

            foreach (self::GROUP_ORDER as $group) {
                if ($remaining <= 0) {
                    break;
                }
                $groupTotal = $totals[$group] ?? 0;
                if ($groupTotal === 0) {
                    continue;
                }
                if ($skip >= $groupTotal) {
                    $skip -= $groupTotal;

                    continue;
                }

                [$chunk] = $this->searchGroup($group, $needle, $skip, $remaining, $user);
                foreach ($chunk as $row) {
                    $row['group'] = $group;
                    $items[] = $row;
                }
                $remaining -= count($chunk);
                $skip = 0;
            }
        } else {
            if (! $this->canSearchGroup($user, $type)) {
                $total = 0;
            } else {
                $total = $totals[$type] ?? 0;
                $offset = ($page - 1) * $perPage;
                [$items] = $this->searchGroup($type, $needle, $offset, $perPage, $user);
                foreach ($items as &$row) {
                    $row['group'] = $type;
                }
                unset($row);
            }
        }

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);

        $groups = $this->emptyGroups();
        foreach ($items as $item) {
            $g = (string) ($item['group'] ?? 'navigation');
            if (! isset($groups[$g])) {
                $groups[$g] = [];
            }
            $groups[$g][] = $item;
        }

        return [
            'q' => $q,
            'type' => $type,
            'totals' => $totals,
            'total' => $total,
            'items' => $items,
            'groups' => $groups,
            'paginator' => $paginator->appends(request()->query()),
        ];
    }

    /**
     * Calidad de coincidencia: exact start < word start < partial < none.
     */
    public function matchRank(string $haystack, string $needle): int
    {
        $hay = mb_strtolower(trim($haystack));
        $needle = mb_strtolower(trim($needle));

        if ($needle === '' || $hay === '') {
            return self::MATCH_NONE;
        }

        if (str_starts_with($hay, $needle)) {
            return self::MATCH_EXACT_START;
        }

        if (preg_match('#(^|[\s\-_/.,;:()]+)'.preg_quote($needle, '#').'#u', $hay) === 1) {
            return self::MATCH_WORD_START;
        }

        if (str_contains($hay, $needle)) {
            return self::MATCH_PARTIAL;
        }

        return self::MATCH_NONE;
    }

    /**
     * Mejor rank entre label y keywords.
     *
     * @param  list<string>  $keywords
     */
    public function bestMatchRank(string $label, array $keywords, string $needle): int
    {
        $best = $this->matchRank($label, $needle);

        foreach ($keywords as $kw) {
            $best = min($best, $this->matchRank((string) $kw, $needle));
        }

        return $best;
    }

    public function normalizeType(string $type): string
    {
        $type = trim($type);
        if ($type === '' || $type === 'all' || $type === 'todos') {
            return 'all';
        }

        return array_key_exists($type, self::GROUP_LABELS) ? $type : 'all';
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchGroup(string $group, string $needle, int $offset, int $limit, ?User $user): array
    {
        return match ($group) {
            'navigation' => $this->searchCatalog('navigation', $needle, $offset, $limit, $user),
            'actions' => $this->searchCatalog('actions', $needle, $offset, $limit, $user),
            'clients' => $this->searchClients($needle, $offset, $limit),
            'suppliers' => $this->searchSuppliers($needle, $offset, $limit),
            'products' => $this->searchProducts($needle, $offset, $limit),
            'equipment' => $this->searchEquipment($needle, $offset, $limit),
            'work_orders' => $this->searchWorkOrders($needle, $offset, $limit),
            'quotations' => $this->searchQuotations($needle, $offset, $limit),
            'sales' => $this->searchSales($needle, $offset, $limit),
            default => [[], 0],
        };
    }

    private function countGroup(string $group, string $needle, ?User $user): int
    {
        return match ($group) {
            'navigation' => $this->countCatalog('navigation', $needle, $user),
            'actions' => $this->countCatalog('actions', $needle, $user),
            'clients' => $this->clientsQuery($needle)->count(),
            'suppliers' => $this->suppliersQuery($needle)->count(),
            'products' => $this->productsQuery($needle)->count(),
            'equipment' => $this->equipmentQuery($needle)->count(),
            'work_orders' => $this->workOrdersQuery($needle)->count(),
            'quotations' => $this->quotationsQuery($needle)->count(),
            'sales' => $this->salesQuery($needle)->count(),
            default => 0,
        };
    }

    private function canSearchGroup(?User $user, string $group): bool
    {
        return match ($group) {
            'navigation', 'actions' => $user !== null,
            'clients' => $this->can($user, 'clients.view'),
            'suppliers' => $this->can($user, 'suppliers.view'),
            'products' => $this->can($user, 'products.view'),
            'equipment' => $this->can($user, 'equipment.view'),
            'work_orders' => $this->can($user, 'work_orders.view'),
            'quotations' => $this->can($user, 'quotations.view'),
            'sales' => $this->can($user, 'sales.view'),
            default => false,
        };
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchCatalog(string $universe, string $needle, int $offset, int $limit, ?User $user): array
    {
        $scored = $this->scoreCatalog($universe, $needle, $user);
        $total = count($scored);

        return [array_slice($scored, $offset, $limit), $total];
    }

    private function countCatalog(string $universe, string $needle, ?User $user): int
    {
        return count($this->scoreCatalog($universe, $needle, $user));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scoreCatalog(string $universe, string $needle, ?User $user): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = config('command_palette.'.$universe, []);
        $scored = [];

        foreach ($entries as $entry) {
            if (! $this->canAny($user, $entry['permission'] ?? null)) {
                continue;
            }

            $routeName = (string) ($entry['route'] ?? '');
            if ($routeName === '' || ! Route::has($routeName)) {
                continue;
            }

            $label = (string) ($entry['label'] ?? '');
            $keywords = array_map('strval', $entry['keywords'] ?? []);
            $rank = $this->bestMatchRank($label, $keywords, $needle);
            if ($rank === self::MATCH_NONE) {
                continue;
            }

            $params = $entry['params'] ?? [];
            $scored[] = [
                'label' => $label,
                'route' => $routeName,
                'params' => $params,
                'subtitle' => $entry['subtitle'] ?? null,
                'url' => route($routeName, $params),
                'match' => $rank,
                'universe' => $universe,
            ];
        }

        usort($scored, function (array $a, array $b): int {
            return ($a['match'] <=> $b['match']) ?: strcmp($a['label'], $b['label']);
        });

        return $scored;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchClients(string $needle, int $offset, int $limit): array
    {
        $query = $this->clientsQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->orderBy('name')->limit(self::RANK_CAP)->get();

        $items = $rows->map(function (Client $c) use ($needle) {
            $label = $c->name;
            $rank = min(
                $this->matchRank($label, $needle),
                $this->matchRank((string) $c->business_name, $needle),
                $this->matchRank((string) $c->cuit, $needle),
                $this->matchRank((string) $c->dni, $needle),
                $this->matchRank((string) $c->email, $needle),
            );

            return [
                'label' => $label,
                'route' => 'clients.show',
                'params' => ['client' => $c->id],
                'subtitle' => collect([$c->cuit, $c->email])->filter()->implode(' · ') ?: null,
                'url' => route('clients.show', $c),
                'match' => $rank === self::MATCH_NONE ? self::MATCH_PARTIAL : $rank,
                'universe' => 'data',
            ];
        })->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchSuppliers(string $needle, int $offset, int $limit): array
    {
        $query = $this->suppliersQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->orderBy('name')->limit(self::RANK_CAP)->get();

        $items = $rows->map(fn (Supplier $s) => [
            'label' => $s->name,
            'route' => 'suppliers.show',
            'params' => ['supplier' => $s->id],
            'subtitle' => collect([$s->cuit, $s->email])->filter()->implode(' · ') ?: null,
            'url' => route('suppliers.show', $s),
            'match' => $this->entityRank($s->name, $needle, [(string) $s->business_name, (string) $s->cuit, (string) $s->email]),
            'universe' => 'data',
        ])->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchProducts(string $needle, int $offset, int $limit): array
    {
        $query = $this->productsQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->orderBy('name')->limit(self::RANK_CAP)->get();

        $items = $rows->map(fn (Product $p) => [
            'label' => $p->name,
            'route' => 'products.show',
            'params' => ['product' => $p->id],
            'subtitle' => trim($p->sku
                .($p->supplier_code ? ' · Prov '.$p->supplier_code : '')
                .($p->part_number ? ' · PN '.$p->part_number : '')
                .($p->brand ? ' · '.$p->brand : '')),
            'url' => route('products.show', $p),
            'match' => $this->entityRank($p->name, $needle, [
                (string) $p->sku,
                (string) $p->brand,
                (string) $p->model,
                (string) $p->supplier_code,
                (string) $p->part_number,
            ]),
            'universe' => 'data',
        ])->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchEquipment(string $needle, int $offset, int $limit): array
    {
        $query = $this->equipmentQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->orderBy('code')->limit(self::RANK_CAP)->get();

        $items = $rows->map(function (Equipment $e) use ($needle) {
            $label = $e->code.($e->name ? ' — '.$e->name : '');

            return [
                'label' => $label,
                'route' => 'equipment.show',
                'params' => ['equipment' => $e->id],
                'subtitle' => $e->status?->value,
                'url' => route('equipment.show', $e),
                'match' => $this->entityRank($label, $needle, [(string) $e->code, (string) $e->name]),
                'universe' => 'data',
            ];
        })->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchWorkOrders(string $needle, int $offset, int $limit): array
    {
        $query = $this->workOrdersQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->with('client')->orderByDesc('id')->limit(self::RANK_CAP)->get();

        $items = $rows->map(function (WorkOrder $wo) use ($needle) {
            $label = $wo->number.($wo->title ? ' — '.$wo->title : '');

            return [
                'label' => $label,
                'route' => 'work-orders.show',
                'params' => ['workOrder' => $wo->id],
                'subtitle' => collect([
                    $wo->client?->name,
                    $wo->status?->label(),
                ])->filter()->implode(' · ') ?: null,
                'url' => route('work-orders.show', $wo),
                'match' => $this->entityRank($label, $needle, [(string) $wo->number, (string) $wo->title]),
                'universe' => 'data',
            ];
        })->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchQuotations(string $needle, int $offset, int $limit): array
    {
        $query = $this->quotationsQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->with('client')->orderByDesc('id')->limit(self::RANK_CAP)->get();

        $items = $rows->map(fn (Quotation $quotation) => [
            'label' => $quotation->number,
            'route' => 'quotations.show',
            'params' => ['quotation' => $quotation->id],
            'subtitle' => collect([
                $quotation->client?->name,
                $quotation->status?->label(),
                $quotation->currency_code.' '.$quotation->total,
            ])->filter()->implode(' · ') ?: null,
            'url' => route('quotations.show', $quotation),
            'match' => $this->entityRank($quotation->number, $needle, [(string) $quotation->notes]),
            'universe' => 'data',
        ])->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function searchSales(string $needle, int $offset, int $limit): array
    {
        $query = $this->salesQuery($needle);
        $total = (clone $query)->count();
        $rows = (clone $query)->with('client')->orderByDesc('id')->limit(self::RANK_CAP)->get();

        $items = $rows->map(fn (Sale $s) => [
            'label' => $s->number,
            'route' => 'sales.show',
            'params' => ['sale' => $s->id],
            'subtitle' => collect([
                $s->client?->name,
                $s->status?->label(),
                $s->currency_code.' '.$s->total,
            ])->filter()->implode(' · ') ?: null,
            'url' => route('sales.show', $s),
            'match' => $this->entityRank($s->number, $needle, [(string) $s->notes]),
            'universe' => 'data',
        ])->sortBy([
            ['match', 'asc'],
            ['label', 'asc'],
        ])->values()->all();

        return [array_slice($items, $offset, $limit), $total];
    }

    private function clientsQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Client::query()->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('business_name', 'like', $like)
                ->orWhere('cuit', 'like', $like)
                ->orWhere('dni', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    private function suppliersQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Supplier::query()->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('business_name', 'like', $like)
                ->orWhere('cuit', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    private function productsQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Product::query()->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhere('model', 'like', $like)
                ->orWhere('supplier_code', 'like', $like)
                ->orWhere('part_number', 'like', $like);
        });
    }

    private function equipmentQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Equipment::query()->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('notes', 'like', $like);
        });
    }

    private function workOrdersQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return WorkOrder::query()->where(function ($query) use ($like) {
            $query->where('number', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    private function quotationsQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Quotation::query()->where(function ($query) use ($like) {
            $query->where('number', 'like', $like)
                ->orWhere('notes', 'like', $like);
        });
    }

    private function salesQuery(string $needle)
    {
        $like = '%'.$needle.'%';

        return Sale::query()->where(function ($query) use ($like) {
            $query->where('number', 'like', $like)
                ->orWhere('notes', 'like', $like);
        });
    }

    /**
     * @param  list<string>  $extra
     */
    private function entityRank(string $label, string $needle, array $extra = []): int
    {
        $best = $this->matchRank($label, $needle);
        foreach ($extra as $field) {
            $best = min($best, $this->matchRank($field, $needle));
        }

        return $best === self::MATCH_NONE ? self::MATCH_PARTIAL : $best;
    }

    private function can(?User $user, string $permission): bool
    {
        return $user !== null && $user->can($permission);
    }

    private function canAny(?User $user, string|array|null $permission): bool
    {
        if ($user === null || $permission === null) {
            return false;
        }

        $list = is_array($permission) ? $permission : [$permission];

        foreach ($list as $perm) {
            if ($user->can((string) $perm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function emptyGroups(): array
    {
        $groups = [];
        foreach (self::GROUP_ORDER as $group) {
            $groups[$group] = [];
        }

        return $groups;
    }

    /**
     * @return array<string, int>
     */
    private function emptyTotals(): array
    {
        $totals = [];
        foreach (self::GROUP_ORDER as $group) {
            $totals[$group] = 0;
        }

        return $totals;
    }

    /**
     * @return array<string, list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url?: string}>>
     */
    private function emptyResult(): array
    {
        return array_merge($this->emptyGroups(), [
            'meta' => [
                'has_more' => [],
                'totals' => $this->emptyTotals(),
                'total' => 0,
            ],
        ]);
    }
}
