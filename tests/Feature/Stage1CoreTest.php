<?php

use App\Models\AuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('la raiz redirige a login cuando no hay sesion', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('el login funciona y redirige a carga rapida si puede crear movimientos', function () {
    $user = makeUserWithPermissions(['dashboard.view', 'movements.create']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('movements.quick'));

    $this->assertAuthenticatedAs($user);
});

test('el login redirige al dashboard si no puede crear movimientos', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('un usuario inactivo no puede iniciar sesion', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('el registro publico esta deshabilitado', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

test('un administrador puede crear usuarios', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/users', [
        'name' => 'Operador Uno',
        'username' => 'operador1',
        'email' => 'operador1@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'status' => 'active',
        'theme' => 'light',
        'roles' => ['Operador'],
    ])->assertRedirect(route('users.index'));

    $this->assertDatabaseHas('users', [
        'email' => 'operador1@example.com',
        'username' => 'operador1',
    ]);

    expect(User::where('email', 'operador1@example.com')->first()->hasRole('Operador'))->toBeTrue();
    expect(AuditLog::where('action', 'created')->exists())->toBeTrue();
});

test('sin permiso users.create no se puede crear usuarios', function () {
    $user = makeUserWithPermissions(['dashboard.view', 'users.view']);

    $this->actingAs($user)->get('/users/create')->assertForbidden();
    $this->actingAs($user)->post('/users', [
        'name' => 'X',
        'username' => 'xuser',
        'email' => 'x@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'status' => 'active',
        'theme' => 'light',
    ])->assertForbidden();
});

test('la matriz de permisos se puede actualizar por rol', function () {
    $admin = makeAdmin();
    $role = Role::findByName('Consulta');

    $this->actingAs($admin)->put(route('permissions.update', $role), [
        'permissions' => ['dashboard.view', 'reports.view'],
    ])->assertRedirect(route('permissions.index'));

    expect($role->fresh()->hasPermissionTo('dashboard.view'))->toBeTrue();
    expect($role->fresh()->hasPermissionTo('reports.view'))->toBeTrue();
    expect($role->fresh()->hasPermissionTo('clients.view'))->toBeFalse();
});

test('el tema claro u oscuro se guarda en el usuario', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)->post(route('theme.update'), [
        'theme' => 'dark',
    ])->assertRedirect();

    expect($user->fresh()->theme)->toBe('dark');
    expect(AuditLog::where('action', 'theme_changed')->exists())->toBeTrue();
});

test('el boton Tema de la navegacion envia el campo theme y alterna preferencia', function () {
    $user = makeUserWithPermissions(['dashboard.view']);
    expect($user->theme)->toBe('light');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('name="theme"', false)
        ->assertSee('value="dark"', false);

    // Mismo payload que genera el formulario de layouts/navigation (toggle light → dark).
    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('theme.update'), ['theme' => 'dark'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertSessionDoesntHaveErrors();

    expect($user->fresh()->theme)->toBe('dark');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('value="light"', false);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('theme.update'), ['theme' => 'light'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->theme)->toBe('light');
});

test('sin campo theme el cambio de tema falla con validation required', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('theme.update'), [])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['theme']);

    expect($user->fresh()->theme)->toBe('light');
});

test('la auditoria lista registros para quien tiene permiso', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/users', [
        'name' => 'Auditado',
        'username' => 'auditado',
        'email' => 'auditado@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'status' => 'active',
        'theme' => 'light',
    ]);

    $this->actingAs($admin)->get(route('audit.index'))->assertOk();
});

test('sin permiso audit.view no se puede ver auditoria', function () {
    $user = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($user)->get(route('audit.index'))->assertForbidden();
});

test('la sidebar agrupa accesos por area y respeta permisos', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('ar-sidebar', false)
        ->assertSee('ar-side-group', false)
        ->assertSee('Inicio')
        ->assertSee('Finanzas')
        ->assertSee('Comercial')
        ->assertSee('Operaciones')
        ->assertSee('Inventario')
        ->assertSee('Reportes')
        ->assertSee('Administración')
        ->assertSee(route('clients.index'), false)
        ->assertSee(route('stock.index'), false)
        ->assertSee(route('users.index'), false);

    $limited = makeUserWithPermissions(['dashboard.view']);

    $this->actingAs($limited)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Inicio')
        ->assertDontSee(route('users.index'), false)
        ->assertDontSee('>Usuarios</a>', false);
});

test('la sidebar abre solo el grupo de la ruta activa al cargar', function () {
    $admin = makeAdmin();

    $dashboard = $this->actingAs($admin)->get(route('dashboard'));
    $dashboard->assertOk()
        ->assertSee('data-sidebar-active="inicio"', false)
        ->assertSee('\u0022inicio\u0022:true', false)
        ->assertSee('\u0022mae\u0022:false', false)
        ->assertSee('\u0022com\u0022:false', false)
        ->assertSee('\u0022inv\u0022:false', false)
        ->assertSee('\u0022adm\u0022:false', false);

    $clients = $this->actingAs($admin)->get(route('clients.index'));
    $clients->assertOk()
        ->assertSee('data-sidebar-active="mae"', false)
        ->assertSee('\u0022mae\u0022:true', false)
        ->assertSee('\u0022inicio\u0022:false', false)
        ->assertSee('\u0022fin\u0022:false', false)
        ->assertSee('\u0022com\u0022:false', false)
        ->assertSee('\u0022inv\u0022:false', false)
        ->assertSee('\u0022ops\u0022:false', false)
        ->assertSee('\u0022rep\u0022:false', false)
        ->assertSee('\u0022adm\u0022:false', false);

    $stock = $this->actingAs($admin)->get(route('stock.index'));
    $stock->assertOk()
        ->assertSee('data-sidebar-active="inv"', false)
        ->assertSee('\u0022inv\u0022:true', false)
        ->assertSee('\u0022mae\u0022:false', false)
        ->assertSee('\u0022com\u0022:false', false);
});

test('roles sembrados existen con permisos granulares', function () {
    seedPermissions();

    expect(Role::findByName('Administrador')->permissions->count())->toBeGreaterThan(10);
    expect(Role::findByName('Operador')->hasPermissionTo('movements.create'))->toBeTrue();
    expect(Role::findByName('Operador')->hasPermissionTo('users.create'))->toBeFalse();
    expect(Role::findByName('Consulta')->hasPermissionTo('dashboard.view'))->toBeTrue();
});
