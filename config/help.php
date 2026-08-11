<?php

return [
    'movements.quick' => [
        'title' => 'Carga de movimiento',
        'summary' => 'Registro rápido de ingresos y egresos financieros personales o profesionales.',
        'bullets' => [
            'Elegí tipo (ingreso/egreso), cuenta, moneda e importe.',
            'El sistema congela el equivalente ARS/USD según la cotización vigente.',
            'No reemplaza compras, ventas ni ajustes de stock.',
        ],
        'flow' => 'Completar datos → Guardar → El movimiento queda posted y afecta saldos de cuenta.',
    ],
    'movements' => [
        'title' => 'Movimientos',
        'summary' => 'Historial de ingresos, egresos y transferencias entre cuentas.',
        'bullets' => [
            'Filtrá por ámbito, tipo y estado.',
            'Un movimiento anulado deja de afectar saldos.',
            'Las transferencias aparecen como par de salida/entrada.',
        ],
        'flow' => 'Consultá el listado → abrí el detalle → usá carga rápida para nuevos movimientos.',
    ],
    'dashboard' => [
        'title' => 'Tablero operativo',
        'summary' => 'Vista consolidada de liquidez, actividad, stock, equipos, OT, ventas y alertas.',
        'bullets' => [
            'Los filtros Personal / Profesional afectan la actividad del mes, no las cuentas líquidas compartidas.',
            'ARS y USD se muestran por separado; no se suman.',
            'Funciona aunque todavía no haya movimientos ni stock.',
        ],
    ],
    'management' => [
        'title' => 'Tablero de gestión',
        'summary' => 'Indicadores de resultado, ranking de CC y Top 5 para decisión gerencial.',
        'bullets' => [
            'Los colores de resultado: verde = favorable, rojo = atención.',
            'En CC de clientes: positivo (rojo) = nos deben; negativo (verde) = a favor del cliente.',
            'Los totales no mezclan ARS con USD.',
        ],
        'flow' => 'Ajustá el período → revisá KPIs → profundizá con el enlace de cada tarjeta.',
    ],
    'accounts' => [
        'title' => 'Cuentas financieras',
        'summary' => 'Cajas, bancos y billeteras donde vive el dinero.',
        'bullets' => [
            'Cada cuenta tiene moneda y tipo (efectivo, banco, billetera u otra).',
            'Los saldos se recalculan desde movimientos confirmados.',
        ],
    ],
    'clients' => [
        'title' => 'Clientes',
        'summary' => 'Maestro de clientes y su cuenta corriente.',
        'bullets' => [
            'Particular: DNI obligatorio; CUIT opcional.',
            'Empresa: CUIT y razón social obligatorios.',
            'La condición fiscal es un catálogo (sin lógica ARCA todavía).',
        ],
        'flow' => 'Convención CC (presentación): + rojo = nos deben; − verde = a favor del cliente.',
    ],
    'clients_cc' => [
        'title' => 'Cuentas corrientes de clientes',
        'summary' => 'Ranking de saldos a cobrar / a favor, con Top deudores.',
        'bullets' => [
            'Saldo positivo (rojo): el cliente nos debe.',
            'Saldo negativo (verde): crédito a favor del cliente.',
            'Cobros y regularización avanzada se documentan para la etapa 11F-3.',
        ],
        'flow' => 'Filtrá → ordená el ranking → abrí la ficha del cliente para el detalle de movimientos.',
    ],
    'suppliers' => [
        'title' => 'Proveedores',
        'summary' => 'Maestro de proveedores y su cuenta corriente.',
        'bullets' => [
            'Misma lógica de tipo Particular / Empresa que clientes.',
            'Las compras generan deuda; los pagos la reducen.',
        ],
    ],
    'purchases' => [
        'title' => 'Compras',
        'summary' => 'Ingreso de mercadería y deuda con proveedores.',
        'bullets' => [
            'Al confirmar una compra se generan lotes FIFO y movimientos de stock.',
            'También impacta la cuenta corriente del proveedor según el modo de pago.',
        ],
    ],
    'products' => [
        'title' => 'Productos',
        'summary' => 'Catálogo de ítems físicos o de servicio.',
        'bullets' => [
            'Los productos físicos participan del stock FIFO.',
            'Desde la ficha podés ajustar, reservar, consumir o transferir.',
        ],
    ],
    'stock' => [
        'title' => 'Stock',
        'summary' => 'Existencias físicas valorizadas por FIFO histórico.',
        'bullets' => [
            'Los ingresos normales vienen de Compras.',
            'Los consumos usan FIFO y no deben editarse a mano el saldo.',
            'Ajustes, reservas y consumos se operan desde la ficha del producto.',
            'Reconstruir solo para usuarios autorizados y con cuidado.',
        ],
        'flow' => 'Sin productos: creá uno en Maestros → Productos. Luego comprá o ajustá desde la ficha.',
    ],
    'equipment' => [
        'title' => 'Equipos',
        'summary' => 'Equipos armados/serializados y su ciclo de vida.',
        'bullets' => [
            'Distinto de un producto genérico de stock.',
            'Podés armar desde plantillas, ver componentes, seriales y estado.',
            'Un equipo vendido no vuelve a ofrecerse en presupuestos/ventas.',
        ],
    ],
    'work_orders' => [
        'title' => 'Órdenes de trabajo',
        'summary' => 'Reparaciones y servicios técnicos.',
        'bullets' => [
            'Seguí estados, consumos e historial del trabajo.',
            'Puede vincularse a cliente y equipos.',
        ],
    ],
    'subscriptions' => [
        'title' => 'Abonos',
        'summary' => 'Cargos recurrentes a clientes.',
        'bullets' => [
            'La generación periódica crea movimientos en cuenta corriente.',
            'No mueve stock por sí sola.',
        ],
    ],
    'quotations' => [
        'title' => 'Presupuestos',
        'summary' => 'Ofertas comerciales sin impacto operativo hasta convertir y confirmar venta.',
        'bullets' => [
            'Producto = ítem de stock; Equipo = unidad armada existente; Servicio = trabajo; Concepto libre = texto.',
            'No modifica stock, cuenta corriente ni dinero hasta convertirse en venta confirmada.',
            'Equipo a fabricar usa tipo/plantilla, no fabrica automáticamente.',
        ],
    ],
    'sales' => [
        'title' => 'Ventas',
        'summary' => 'Confirmación comercial con impacto en stock, CC y/o caja.',
        'bullets' => [
            'Crédito: baja stock y aumenta deuda del cliente, sin ingreso bancario.',
            'Contado: baja stock e ingresa dinero; la CC no queda con deuda.',
            'Equipo vendido cambia de estado y no re-consume componentes.',
        ],
    ],
    'chart_accounts' => [
        'title' => 'Plan de cuentas',
        'summary' => 'Clasificación contable usada por categorías y reportes.',
        'bullets' => [
            'Los tipos internos (income, expense, etc.) se muestran en español en la UI.',
            'Las categorías financieras pueden asociarse a una cuenta del plan.',
        ],
    ],
    'reports' => [
        'title' => 'Reportes',
        'summary' => 'Consultas y exportaciones de finanzas, stock y comercial.',
        'bullets' => [
            'Usá filtros de fecha cuando estén disponibles.',
            'Exportá CSV/XLSX/PDF según permisos.',
        ],
    ],
    'imports' => [
        'title' => 'Importaciones',
        'summary' => 'Carga asistida de datos e importación histórica controlada.',
        'bullets' => [
            'Revisá previsualizaciones antes de confirmar.',
            'No altera saldos sin un flujo de importación explícito.',
            'Los lotes quedan auditados.',
        ],
    ],
    'users' => [
        'title' => 'Usuarios y permisos',
        'summary' => 'Acceso al sistema y roles Spatie.',
        'bullets' => [
            'Los permisos controlan menús y acciones sensibles (p. ej. reconstruir stock).',
            'No compartas credenciales de staging/producción.',
        ],
    ],
    'permissions' => [
        'title' => 'Matriz de permisos',
        'summary' => 'Asignación de permisos por rol.',
        'bullets' => [
            'Cambiar un permiso impacta menús, APIs y acciones protegidas.',
            'Revisá el efecto antes de guardar en staging/producción.',
        ],
    ],
    'audit' => [
        'title' => 'Auditoría',
        'summary' => 'Trazabilidad de acciones sensibles del sistema.',
        'bullets' => [
            'Cada evento registra usuario, acción y, cuando aplica, valores anteriores/nuevos.',
            'Útil para investigar cambios de apariencia, movimientos y maestros.',
        ],
    ],
];
