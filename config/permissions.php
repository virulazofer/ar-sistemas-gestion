<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo de áreas y acciones (matriz de permisos)
    |--------------------------------------------------------------------------
    |
    | Los permisos se materializan como "{area}.{accion}" (ej: users.view).
    | Las áreas futuras se definen aquí desde la Etapa 1 para que la matriz
    | ya exista; los módulos se implementan en etapas posteriores.
    |
    */

    'actions' => [
        'view' => 'Ver',
        'create' => 'Crear',
        'edit' => 'Editar',
        'void' => 'Anular',
        'delete' => 'Eliminar',
        'export' => 'Exportar',
        'adjust' => 'Ajustar',
        'transfer' => 'Transferir',
        'consume' => 'Consumir',
        'assemble' => 'Armar',
        'disassemble' => 'Desarmar',
        'change_component' => 'Cambiar componente',
        'change_status' => 'Cambiar estado',
        'rebuild' => 'Reconstruir',
        'close' => 'Cerrar',
        'cancel' => 'Cancelar',
        'consume_stock' => 'Consumir stock',
        'charge' => 'Cargar CC',
        'generate' => 'Generar',
        'send' => 'Enviar',
        'accept' => 'Aceptar',
        'convert' => 'Convertir',
        'execute' => 'Ejecutar',
        'finance' => 'Finanzas',
        'clients' => 'Clientes',
        'suppliers' => 'Proveedores',
        'stock' => 'Stock',
        'sales' => 'Ventas',
        'profitability' => 'Rentabilidad',
    ],

    'areas' => [
        'users' => 'Usuarios',
        'permissions' => 'Permisos',
        'settings' => 'Configuración',
        'audit' => 'Auditoría',
        'dashboard' => 'Dashboard',
        'movements' => 'Movimientos',
        'accounts' => 'Cuentas financieras',
        'exchange_rates' => 'Cotizaciones',
        'categories' => 'Categorías',
        'clients' => 'Clientes',
        'suppliers' => 'Proveedores',
        'products' => 'Productos',
        'stock' => 'Stock',
        'purchases' => 'Compras',
        'equipment' => 'Equipos',
        'work_orders' => 'Trabajos',
        'subscriptions' => 'Abonos',
        'events' => 'Eventos',
        'quotations' => 'Presupuestos',
        'sales' => 'Ventas',
        'invoices' => 'Facturas',
        'documents' => 'Documentos',
        'imports' => 'Importaciones',
        'reports' => 'Reportes',
        'exports' => 'Exportaciones',
    ],

    /*
    | Acciones habilitadas por área (vacío = todas).
    | Evita casillas sin sentido (ej: reportes sin "crear").
    */
    'area_actions' => [
        'users' => ['view', 'create', 'edit', 'delete'],
        'permissions' => ['view', 'edit'],
        'settings' => ['view', 'edit'],
        'audit' => ['view', 'export'],
        'dashboard' => ['view'],
        'movements' => ['view', 'create', 'edit', 'void', 'export'],
        'accounts' => ['view', 'create', 'edit'],
        'exchange_rates' => ['view', 'create', 'edit'],
        'categories' => ['view', 'create', 'edit'],
        'clients' => ['view', 'create', 'edit', 'void', 'export'],
        'suppliers' => ['view', 'create', 'edit', 'void', 'export'],
        'products' => ['view', 'create', 'edit', 'void', 'export'],
        'stock' => ['view', 'create', 'edit', 'void', 'adjust', 'transfer', 'consume', 'rebuild', 'export'],
        'purchases' => ['view', 'create', 'edit', 'void', 'export'],
        'equipment' => ['view', 'create', 'edit', 'void', 'assemble', 'disassemble', 'change_component', 'change_status', 'export'],
        'work_orders' => ['view', 'create', 'edit', 'void', 'close', 'cancel', 'consume_stock', 'charge', 'export'],
        'subscriptions' => ['view', 'create', 'edit', 'void', 'generate', 'cancel', 'export'],
        'events' => ['view', 'create', 'edit', 'void', 'export'],
        'quotations' => ['view', 'create', 'edit', 'send', 'accept', 'convert', 'cancel', 'export'],
        'sales' => ['view', 'create', 'edit', 'confirm', 'void', 'export'],
        'invoices' => ['view', 'create', 'edit', 'void', 'export'],
        'documents' => ['view', 'create', 'edit', 'delete'],
        'imports' => ['view', 'execute'],
        'reports' => ['view', 'export', 'finance', 'clients', 'suppliers', 'stock', 'sales', 'profitability'],
        'exports' => ['execute'],
    ],
];
