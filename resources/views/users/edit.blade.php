<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Editar usuario</h1>
    </x-slot>

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('mail_diagnosis'))
        <p class="ar-card mb-4 p-3 text-sm" style="border-color: #b45309;">{{ session('mail_diagnosis') }}</p>
    @endif

    <form method="POST" action="{{ route('users.update', $user) }}" class="ar-card mx-auto max-w-2xl space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('users._form', ['user' => $user])
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('users.index') }}" class="ar-btn ar-btn-secondary">Cancelar</a>
            <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>
        </div>
    </form>

    <div class="ar-card mx-auto mt-4 max-w-2xl space-y-3 p-6">
        <h2 class="font-semibold">Correo y contraseña</h2>
        <p class="text-sm">Verificado: <strong>{{ $user->hasVerifiedEmail() ? 'Sí' : 'No' }}</strong></p>
        <p class="ar-muted text-xs">{{ $mailDiagnosis ?? '' }}</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('users.send-reset', $user) }}">
                @csrf
                <button class="ar-btn ar-btn-secondary">Enviar enlace para restablecer contraseña</button>
            </form>
            @unless ($user->hasVerifiedEmail())
                <form method="POST" action="{{ route('users.send-verification', $user) }}">
                    @csrf
                    <button class="ar-btn ar-btn-secondary">Reenviar verificación de correo</button>
                </form>
            @endunless
        </div>
        <p class="ar-muted text-xs">Nunca se muestra ni se almacena la contraseña en texto plano.</p>
    </div>
</x-app-layout>
