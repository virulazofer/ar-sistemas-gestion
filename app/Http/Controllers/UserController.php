<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $users = User::query()->with('roles')->orderBy('name')->paginate(15);

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        $roles = Role::query()->orderBy('name')->get();

        return view('users.create', compact('roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'theme' => ['required', Rule::in([User::THEME_LIGHT, User::THEME_DARK])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => $data['status'],
            'theme' => $data['theme'],
            'email_verified_at' => now(),
        ]);

        $user->syncRoles($data['roles'] ?? []);

        $this->audit->log('created', $user, null, $user->only(['name', 'username', 'email', 'status', 'theme']), 'Usuario creado');

        return redirect()->route('users.index')->with('status', 'Usuario creado correctamente.');
    }

    public function show(User $user): View
    {
        $user->load('roles', 'permissions');

        return view('users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        $roles = Role::query()->orderBy('name')->get();
        $user->load('roles');

        return view('users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'theme' => ['required', Rule::in([User::THEME_LIGHT, User::THEME_DARK])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $old = $user->only(['name', 'username', 'email', 'status', 'theme']);

        $payload = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'status' => $data['status'],
            'theme' => $data['theme'],
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);
        $user->syncRoles($data['roles'] ?? []);

        $this->audit->log('updated', $user, $old, $user->only(['name', 'username', 'email', 'status', 'theme']), 'Usuario actualizado');

        return redirect()->route('users.index')->with('status', 'Usuario actualizado correctamente.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'No podés eliminar tu propio usuario.']);
        }

        $old = $user->only(['name', 'username', 'email', 'status']);
        $user->delete();

        $this->audit->log('deleted', null, $old, null, "Usuario eliminado: {$old['email']}");

        return redirect()->route('users.index')->with('status', 'Usuario eliminado.');
    }
}
