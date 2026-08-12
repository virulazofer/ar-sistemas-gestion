<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\MailDiagnosis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
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
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'theme' => ['required', Rule::in([User::THEME_LIGHT, User::THEME_DARK])],
            'palette' => ['nullable', Rule::in(\App\Support\Appearance::palettes())],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'send_verification' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => $data['status'],
            'theme' => $data['theme'],
            'palette' => \App\Support\Appearance::normalizePalette($data['palette'] ?? null),
            'email_verified_at' => null,
        ]);

        $user->syncRoles($data['roles'] ?? []);

        $this->audit->log('created', $user, null, $user->only(['name', 'username', 'email', 'status', 'theme', 'palette']), 'Usuario creado');

        $mailNote = MailDiagnosis::message();
        if ($request->boolean('send_verification', true)) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                $mailNote .= ' Error al encolar verificación: '.$e->getMessage();
            }
        }

        return redirect()->route('users.index')
            ->with('status', 'Usuario creado. Se solicitó verificación de correo (sin mostrar contraseña).')
            ->with('mail_diagnosis', $mailNote);
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
        $mailDiagnosis = MailDiagnosis::message();

        return view('users.edit', compact('user', 'roles', 'mailDiagnosis'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', PasswordRule::defaults()],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'theme' => ['required', Rule::in([User::THEME_LIGHT, User::THEME_DARK])],
            'palette' => ['nullable', Rule::in(\App\Support\Appearance::palettes())],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $old = $user->only(['name', 'username', 'email', 'status', 'theme', 'palette', 'email_verified_at']);

        $payload = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'status' => $data['status'],
            'theme' => $data['theme'],
            'palette' => \App\Support\Appearance::normalizePalette($data['palette'] ?? $user->palette),
        ];

        if ($data['email'] !== $user->email) {
            $payload['email_verified_at'] = null;
        }

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);
        $user->syncRoles($data['roles'] ?? []);

        $this->audit->log('updated', $user, $old, $user->only(['name', 'username', 'email', 'status', 'theme', 'palette', 'email_verified_at']), 'Usuario actualizado');

        return redirect()->route('users.index')->with('status', 'Usuario actualizado correctamente.');
    }

    public function sendPasswordReset(User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);
        $this->audit->log('password_reset_link_sent', $user, null, ['email' => $user->email, 'status' => $status], 'Admin envió enlace de restablecimiento');

        return back()
            ->with('status', $status === Password::RESET_LINK_SENT
                ? 'Enlace de restablecimiento enviado (sin exponer contraseña).'
                : 'No se pudo enviar el enlace.')
            ->with('mail_diagnosis', MailDiagnosis::message());
    }

    public function sendVerification(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('status', 'El correo ya está verificado.');
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            return back()->withErrors(['email' => 'No se pudo enviar: '.$e->getMessage()])
                ->with('mail_diagnosis', MailDiagnosis::message());
        }

        $this->audit->log('verification_link_sent', $user, null, ['email' => $user->email], 'Admin reenvió verificación');

        return back()
            ->with('status', 'Enlace de verificación enviado.')
            ->with('mail_diagnosis', MailDiagnosis::message());
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
