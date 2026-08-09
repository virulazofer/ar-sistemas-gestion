<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-semibold">Matriz de permisos</h1>
            <p class="ar-muted text-sm">Asigná permisos por rol. La autorización se aplica en el backend.</p>
        </div>
    </x-slot>

    <div class="space-y-8" x-data="{ roleTab: '{{ $roles->first()?->id }}' }">
        <div class="flex flex-wrap gap-2">
            @foreach ($roles as $role)
                <button type="button" class="ar-btn" :class="roleTab == '{{ $role->id }}' ? 'ar-btn-primary' : 'ar-btn-secondary'" @click="roleTab = '{{ $role->id }}'">
                    {{ $role->name }}
                </button>
            @endforeach
        </div>

        @foreach ($roles as $role)
            <div x-show="roleTab == '{{ $role->id }}'" class="ar-card overflow-x-auto p-4" style="display: none;" x-cloak>
                <form method="POST" action="{{ route('permissions.update', $role) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="font-semibold">{{ $role->name }}</h2>
                        @can('permissions.edit')
                            <button type="submit" class="ar-btn ar-btn-primary">Guardar permisos</button>
                        @endcan
                    </div>

                    <table class="ar-table">
                        <thead>
                            <tr>
                                <th>Área</th>
                                @foreach ($actions as $action => $actionLabel)
                                    <th class="text-center">{{ $actionLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($areas as $area => $areaLabel)
                                @php $allowed = $areaActions[$area] ?? array_keys($actions); @endphp
                                <tr>
                                    <td class="font-medium">{{ $areaLabel }}</td>
                                    @foreach ($actions as $action => $actionLabel)
                                        <td class="text-center">
                                            @if (in_array($action, $allowed, true))
                                                @php $perm = "{$area}.{$action}"; @endphp
                                                <input type="checkbox"
                                                       name="permissions[]"
                                                       value="{{ $perm }}"
                                                       @checked($role->hasPermissionTo($perm))
                                                       @disabled(! auth()->user()->can('permissions.edit'))>
                                            @else
                                                <span class="ar-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </form>
            </div>
        @endforeach
    </div>
</x-app-layout>
