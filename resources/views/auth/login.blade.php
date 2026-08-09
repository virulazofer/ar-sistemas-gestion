<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <h1 class="mb-4 text-lg font-semibold">Iniciar sesión</h1>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label class="ar-label" for="email">Email</label>
            <input id="email" class="ar-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <label class="ar-label" for="password">Contraseña</label>
            <input id="password" class="ar-input" type="password" name="password" required autocomplete="current-password">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember">
            Recordarme
        </label>

        <div class="flex items-center justify-between gap-3 pt-2">
            @if (Route::has('password.request'))
                <a class="ar-muted text-sm underline" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
            @endif
            <button type="submit" class="ar-btn ar-btn-primary">Entrar</button>
        </div>
    </form>
</x-guest-layout>
