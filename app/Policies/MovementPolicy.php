<?php

namespace App\Policies;

use App\Models\Movement;
use App\Models\User;

/**
 * Política de backend: no alcanza con ocultar botones en Blade.
 */
class MovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('movements.view');
    }

    public function view(User $user, Movement $movement): bool
    {
        return $user->can('movements.view');
    }

    public function create(User $user): bool
    {
        return $user->can('movements.create');
    }

    public function update(User $user, Movement $movement): bool
    {
        if (! $user->can('movements.edit')) {
            return false;
        }

        return $movement->isPosted();
    }

    public function void(User $user, Movement $movement): bool
    {
        if (! $user->can('movements.void')) {
            return false;
        }

        return $movement->isPosted();
    }
}
