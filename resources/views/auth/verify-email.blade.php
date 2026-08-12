<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        Antes de continuar, verificá tu correo electrónico con el enlace que te enviamos.
        Si no lo recibiste, podemos reenviarlo.
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            Se envió un nuevo enlace de verificación a tu correo.
        </div>
    @endif

    @if (session('mail_diagnosis'))
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            {{ session('mail_diagnosis') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <div>
                <x-primary-button>Reenviar correo de verificación</x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md">
                Cerrar sesión
            </button>
        </form>
    </div>
</x-guest-layout>
