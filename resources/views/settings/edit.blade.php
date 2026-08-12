<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Configuración</h1>
            <x-page-help topic="settings" />
        </div>
    </x-slot>

    @if (session('status'))
        <p class="ar-card mb-4 p-3 text-sm">{{ session('status') }}</p>
    @endif

    @php
        $groupLabels = [
            'general' => 'General',
            'appearance' => 'Apariencia',
            'apariencia' => 'Apariencia',
            'commercial' => 'Comercial',
            'inventory' => 'Inventario',
            'work_orders' => 'Órdenes de trabajo',
            'mail' => 'Correo',
            'finance' => 'Finanzas',
        ];
    @endphp

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        @foreach ($settings as $group => $items)
            <div class="ar-card p-5">
                <h2 class="mb-4 font-semibold">{{ $groupLabels[$group] ?? ucfirst(str_replace('_', ' ', $group)) }}</h2>
                <div class="space-y-4">
                    @foreach ($items as $setting)
                        <div>
                            <label class="ar-label" for="setting-{{ $setting->id }}">{{ $setting->label ?: $setting->key }}</label>
                            <input id="setting-{{ $setting->id }}"
                                   type="text"
                                   name="settings[{{ $setting->key }}]"
                                   value="{{ old('settings.'.$setting->key, $setting->value) }}"
                                   class="ar-input"
                                   @disabled(! auth()->user()->can('settings.edit'))>
                            @if ($setting->description)
                                <p class="ar-muted mt-1 text-xs">{{ $setting->description }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @can('settings.edit')
            <div class="flex justify-end">
                <button type="submit" class="ar-btn ar-btn-primary">Guardar</button>
            </div>
        @endcan
    </form>
</x-app-layout>
