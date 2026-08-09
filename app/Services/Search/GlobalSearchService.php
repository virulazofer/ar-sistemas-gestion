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
use Illuminate\Support\Facades\Auth;

class GlobalSearchService
{
    /**
     * @return array{
     *   clients: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   suppliers: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   products: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   equipment: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   work_orders: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   quotations: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     *   sales: list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url: string}>,
     * }
     */
    public function search(string $q, int $limit = 8, ?User $user = null): array
    {
        $q = trim($q);
        $limit = max(1, min(25, $limit));
        $user ??= Auth::user();

        if (mb_strlen($q) < 2) {
            return $this->emptyResult();
        }

        $like = '%'.$q.'%';
        $out = $this->emptyResult();

        if ($this->can($user, 'clients.view')) {
            $out['clients'] = Client::query()
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('business_name', 'like', $like)
                        ->orWhere('cuit', 'like', $like)
                        ->orWhere('dni', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (Client $c) => [
                    'label' => $c->name,
                    'route' => 'clients.show',
                    'params' => ['client' => $c->id],
                    'subtitle' => collect([$c->cuit, $c->email])->filter()->implode(' · ') ?: null,
                    'url' => route('clients.show', $c),
                ])
                ->all();
        }

        if ($this->can($user, 'suppliers.view')) {
            $out['suppliers'] = Supplier::query()
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('business_name', 'like', $like)
                        ->orWhere('cuit', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (Supplier $s) => [
                    'label' => $s->name,
                    'route' => 'suppliers.show',
                    'params' => ['supplier' => $s->id],
                    'subtitle' => collect([$s->cuit, $s->email])->filter()->implode(' · ') ?: null,
                    'url' => route('suppliers.show', $s),
                ])
                ->all();
        }

        if ($this->can($user, 'products.view')) {
            $out['products'] = Product::query()
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('model', 'like', $like)
                        ->orWhere('supplier_code', 'like', $like)
                        ->orWhere('part_number', 'like', $like);
                })
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(fn (Product $p) => [
                    'label' => $p->name,
                    'route' => 'products.show',
                    'params' => ['product' => $p->id],
                    'subtitle' => trim($p->sku
                        .($p->supplier_code ? ' · Prov '.$p->supplier_code : '')
                        .($p->part_number ? ' · PN '.$p->part_number : '')
                        .($p->brand ? ' · '.$p->brand : '')),
                    'url' => route('products.show', $p),
                ])
                ->all();
        }

        if ($this->can($user, 'equipment.view')) {
            $out['equipment'] = Equipment::query()
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                })
                ->orderBy('code')
                ->limit($limit)
                ->get()
                ->map(fn (Equipment $e) => [
                    'label' => $e->code.($e->name ? ' — '.$e->name : ''),
                    'route' => 'equipment.show',
                    'params' => ['equipment' => $e->id],
                    'subtitle' => $e->status?->value,
                    'url' => route('equipment.show', $e),
                ])
                ->all();
        }

        if ($this->can($user, 'work_orders.view')) {
            $out['work_orders'] = WorkOrder::query()
                ->with('client')
                ->where(function ($query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('description', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (WorkOrder $wo) => [
                    'label' => $wo->number.($wo->title ? ' — '.$wo->title : ''),
                    'route' => 'work-orders.show',
                    'params' => ['workOrder' => $wo->id],
                    'subtitle' => collect([
                        $wo->client?->name,
                        $wo->status?->label(),
                    ])->filter()->implode(' · ') ?: null,
                    'url' => route('work-orders.show', $wo),
                ])
                ->all();
        }

        if ($this->can($user, 'quotations.view')) {
            $out['quotations'] = Quotation::query()
                ->with('client')
                ->where(function ($query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (Quotation $quotation) => [
                    'label' => $quotation->number,
                    'route' => 'quotations.show',
                    'params' => ['quotation' => $quotation->id],
                    'subtitle' => collect([
                        $quotation->client?->name,
                        $quotation->status?->label(),
                        $quotation->currency_code.' '.$quotation->total,
                    ])->filter()->implode(' · ') ?: null,
                    'url' => route('quotations.show', $quotation),
                ])
                ->all();
        }

        if ($this->can($user, 'sales.view')) {
            $out['sales'] = Sale::query()
                ->with('client')
                ->where(function ($query) use ($like) {
                    $query->where('number', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (Sale $s) => [
                    'label' => $s->number,
                    'route' => 'sales.show',
                    'params' => ['sale' => $s->id],
                    'subtitle' => collect([
                        $s->client?->name,
                        $s->status?->label(),
                        $s->currency_code.' '.$s->total,
                    ])->filter()->implode(' · ') ?: null,
                    'url' => route('sales.show', $s),
                ])
                ->all();
        }

        return $out;
    }

    private function can(?User $user, string $permission): bool
    {
        return $user !== null && $user->can($permission);
    }

    /**
     * @return array<string, list<array{label: string, route: string, params: array<string, mixed>, subtitle: string|null, url?: string}>>
     */
    private function emptyResult(): array
    {
        return [
            'clients' => [],
            'suppliers' => [],
            'products' => [],
            'equipment' => [],
            'work_orders' => [],
            'quotations' => [],
            'sales' => [],
        ];
    }
}
