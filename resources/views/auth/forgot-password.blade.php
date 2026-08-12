<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        ¿Olvidaste tu contraseña? Ingresá tu email y te enviaremos un enlace temporal para elegir una nueva.
        Nunca mostramos ni enviamos contraseñas en texto plano.
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (session('mail_diagnosis'))
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            {{ session('mail_diagnosis') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div>
            <x-input-label for="email" value="Correo electrónico" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Enviar enlace para restablecer</x-primary-button>
        </div>
    </form>
</x-guest-layout>
