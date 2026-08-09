<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();
        $areas = config('permissions.areas');
        $actions = config('permissions.actions');
        $areaActions = config('permissions.area_actions');
        $permissions = Permission::query()->pluck('name')->all();

        return view('permissions.matrix', compact('roles', 'areas', 'actions', 'areaActions', 'permissions'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $old = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions'] ?? []);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $new = $role->permissions()->pluck('name')->sort()->values()->all();

        $this->audit->log(
            'permissions_updated',
            $role,
            ['permissions' => $old],
            ['permissions' => $new],
            "Permisos actualizados para el rol {$role->name}"
        );

        return redirect()->route('permissions.index')->with('status', "Permisos de {$role->name} actualizados.");
    }
}
