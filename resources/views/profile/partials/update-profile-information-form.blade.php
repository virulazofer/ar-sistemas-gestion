<section>
    <header>
        <h2 class="text-lg font-medium">
            Información del perfil
        </h2>
        <p class="ar-muted mt-1 text-sm">
            Actualizá tu nombre y email.
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <label class="ar-label" for="name">Nombre</label>
            <input id="name" name="name" type="text" class="ar-input" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name">
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <label class="ar-label" for="email">Email</label>
            <input id="email" name="email" type="email" class="ar-input" value="{{ old('email', $user->email) }}" required autocomplete="username">
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="ar-muted text-sm"
                >Guardado.</p>
            @endif
        </div>
    </form>
</section>
