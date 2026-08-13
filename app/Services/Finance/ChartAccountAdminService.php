<?php

namespace App\Services\Finance;

use App\Enums\ChartAccountType;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\Movement;
use App\Models\Subcategory;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChartAccountAdminService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code:string,name:string,type:string,parent_id?:int|null,sort_order?:int,is_active?:bool,help_text?:?string,suggested_scope?:?string}  $data
     */
    public function create(array $data): ChartAccount
    {
        $parentId = $data['parent_id'] ?? null;
        if ($parentId === null) {
            throw new InvalidArgumentException('Solo existen cinco raíces estructurales; creá cuentas debajo de ellas.');
        }

        $parent = ChartAccount::query()->findOrFail($parentId);
        $type = ChartAccountType::from($data['type']);
        if ($parent->type !== $type) {
            throw new InvalidArgumentException('El tipo debe coincidir con la raíz/padre ('.$parent->typeLabel().').');
        }

        $account = ChartAccount::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $type,
            'parent_id' => $parent->id,
            'is_active' => $data['is_active'] ?? true,
            'is_protected' => false,
            'help_text' => $data['help_text'] ?? null,
            'suggested_scope' => $data['suggested_scope'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 100),
        ]);

        $this->audit->log('chart_account_created', $account, null, $account->toArray(), 'Cuenta contable creada');

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ChartAccount $account, array $data): ChartAccount
    {
        if ($account->isProtectedRoot()) {
            $forbidden = [];
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $forbidden[] = 'mover';
            }
            if (isset($data['type']) && (string) $data['type'] !== $account->type->value) {
                $forbidden[] = 'cambiar naturaleza';
            }
            if ($forbidden !== []) {
                throw new InvalidArgumentException('Las raíces estructurales no se pueden '.implode('/', $forbidden).'.');
            }
            // Permitir editar nombre/código/help de raíces con cuidado; naturaleza y parent no.
            unset($data['parent_id'], $data['type'], $data['is_protected']);
        }

        $parentId = array_key_exists('parent_id', $data)
            ? ($data['parent_id'] !== null && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null)
            : $account->parent_id;

        if ($parentId === (int) $account->id) {
            throw new InvalidArgumentException('Una cuenta no puede ser padre de sí misma.');
        }
        if ($parentId !== null && $this->isDescendant($account, $parentId)) {
            throw new InvalidArgumentException('No se puede asignar como padre una cuenta hija (ciclo).');
        }
        if ($parentId !== null) {
            $parent = ChartAccount::query()->findOrFail($parentId);
            $newType = isset($data['type']) ? ChartAccountType::from($data['type']) : $account->type;
            if ($parent->type !== $newType) {
                throw new InvalidArgumentException('Solo se puede mover dentro de una raíz de la misma naturaleza.');
            }
            $data['parent_id'] = $parentId;
        } elseif (! $account->isProtectedRoot() && array_key_exists('parent_id', $data)) {
            throw new InvalidArgumentException('Las cuentas inferiores deben tener padre.');
        }

        $allowed = ['code', 'name', 'type', 'parent_id', 'is_active', 'help_text', 'suggested_scope', 'sort_order'];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        $old = $account->toArray();
        $account->update($payload);

        $this->audit->log('chart_account_updated', $account, $old, $account->fresh()->toArray(), 'Cuenta contable actualizada');

        return $account->fresh();
    }

    public function move(ChartAccount $account, int $newParentId): ChartAccount
    {
        return $this->update($account, ['parent_id' => $newParentId, 'type' => $account->type->value]);
    }

    /**
     * @param  array{disposition:string,reassign_to?:int|null,children_action?:string}  $options
     */
    public function delete(ChartAccount $account, array $options): void
    {
        if ($account->isProtectedRoot()) {
            throw new InvalidArgumentException('No se pueden eliminar las cinco raíces estructurales.');
        }

        $disposition = $options['disposition'] ?? 'cancel';
        if ($disposition === 'cancel') {
            return;
        }

        $targetId = $disposition === 'reassign' ? (int) ($options['reassign_to'] ?? 0) : null;
        if ($disposition === 'reassign' && ! $targetId) {
            throw new InvalidArgumentException('Elegí la cuenta de destino para reasignar.');
        }
        if ($targetId && ($targetId === (int) $account->id || $this->isDescendant($account, $targetId))) {
            throw new InvalidArgumentException('Destino de reasignación inválido (ciclo).');
        }

        $childrenCount = $account->children()->count();
        $childrenAction = $options['children_action'] ?? 'reparent';
        if ($childrenCount > 0 && $childrenAction === 'block') {
            throw new InvalidArgumentException('Esta cuenta tiene subcuentas. Decidí qué hacer con ellas.');
        }

        $usage = $this->usage($account);
        $snapshot = $account->toArray();

        DB::transaction(function () use ($account, $targetId, $childrenCount, $usage, $snapshot) {
            if ($childrenCount > 0) {
                ChartAccount::query()
                    ->where('parent_id', $account->id)
                    ->update(['parent_id' => $targetId ?? $account->parent_id]);
            }

            Category::query()->where('chart_account_id', $account->id)->update(['chart_account_id' => $targetId]);
            Subcategory::query()->where('chart_account_id', $account->id)->update(['chart_account_id' => $targetId]);
            Movement::query()->where('chart_account_id', $account->id)->update(['chart_account_id' => $targetId]);
            FinancialAccount::query()->where('chart_account_id', $account->id)->update(['chart_account_id' => $targetId]);

            $account->delete();

            $this->audit->log('chart_account_deleted', null, $snapshot, [
                'disposition' => $targetId ? 'reassign' : 'unassign',
                'reassign_to' => $targetId,
                'children_reparented' => $childrenCount,
                'usage' => $usage,
            ], 'Cuenta contable eliminada');
        });
    }

    /**
     * @return array{categories:int,subcategories:int,movements:int,children:int,financial_accounts:int}
     */
    public function usage(ChartAccount $account): array
    {
        return [
            'categories' => Category::query()->where('chart_account_id', $account->id)->count(),
            'subcategories' => Subcategory::query()->where('chart_account_id', $account->id)->count(),
            'movements' => Movement::query()->where('chart_account_id', $account->id)->count(),
            'children' => $account->children()->count(),
            'financial_accounts' => FinancialAccount::query()->where('chart_account_id', $account->id)->count(),
        ];
    }

    public function search(string $term, ?string $type = null, int $limit = 40): array
    {
        $term = trim($term);
        $q = ChartAccount::query()->active()->whereNotNull('parent_id');
        if ($type) {
            $q->where('type', $type);
        }
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)->orWhere('code', 'like', $like);
            });
        }

        return $q->orderBy('code')->limit($limit)->get()->map(fn (ChartAccount $a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'type' => $a->type->value,
            'path' => $a->pathLabel(),
            'suggested_scope' => $a->suggested_scope,
            'is_leaf' => $a->isLeaf(),
        ])->all();
    }

    private function isDescendant(ChartAccount $root, int $candidateId): bool
    {
        return in_array($candidateId, $root->descendantIds(), true);
    }
}
