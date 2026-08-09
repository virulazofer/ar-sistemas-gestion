<div>
    <label class="ar-label" for="name">Nombre</label>
    <input id="name" name="name" type="text" class="ar-input" value="{{ old('name', $user?->name) }}" required>
</div>

<div>
    <label class="ar-label" for="username">Usuario</label>
    <input id="username" name="username" type="text" class="ar-input" value="{{ old('username', $user?->username) }}" required>
</div>

<div>
    <label class="ar-label" for="email">Email</label>
    <input id="email" name="email" type="email" class="ar-input" value="{{ old('email', $user?->email) }}" required>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label" for="password">Contraseña {{ $user ? '(opcional)' : '' }}</label>
        <input id="password" name="password" type="password" class="ar-input" {{ $user ? '' : 'required' }}>
    </div>
    <div>
        <label class="ar-label" for="password_confirmation">Confirmar contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="ar-input" {{ $user ? '' : 'required' }}>
    </div>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="ar-label" for="status">Estado</label>
        <select id="status" name="status" class="ar-input">
            <option value="active" @selected(old('status', $user?->status ?? 'active') === 'active')>Activo</option>
            <option value="inactive" @selected(old('status', $user?->status) === 'inactive')>Inactivo</option>
        </select>
    </div>
    <div>
        <label class="ar-label" for="theme">Tema</label>
        <select id="theme" name="theme" class="ar-input">
            <option value="light" @selected(old('theme', $user?->theme ?? 'light') === 'light')>Claro</option>
            <option value="dark" @selected(old('theme', $user?->theme) === 'dark')>Oscuro</option>
        </select>
    </div>
</div>

<div>
    <p class="ar-label">Roles</p>
    <div class="grid gap-2 sm:grid-cols-3">
        @foreach ($roles as $role)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="roles[]" value="{{ $role->name }}"
                    @checked(collect(old('roles', $user?->roles?->pluck('name')->all() ?? []))->contains($role->name))>
                {{ $role->name }}
            </label>
        @endforeach
    </div>
</div>
