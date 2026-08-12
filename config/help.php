<?php

return [
    'movements.quick' => [
        'title' => 'Carga de movimiento',
        'summary' => 'Registro rápido de ingresos, egresos y transferencias. Orden: Fecha → Ámbito → Descripción → Categoría → Subcategoría → Importe → Cuenta financiera.',
        'bullets' => [
            'Ingreso: Cliente opcional + «Aplicar a CC» reutiliza el cobro (un solo crédito financiero).',
            'Sin deuda suficiente: A pago a cuenta · B crear cargo+aplicar · C solo ingreso · D cancelar. No se inventa deuda.',
            'Gasto en tarjeta/pasivo: etiqueta «Cuenta debitada (pasivo)»; el pago de resumen es transferencia.',
            'El sistema congela ARS/USD según la cotización de la fecha del movimiento.',
        ],
        'diagram' => "Movimiento\n├─ Cuenta financiera (caja/banco/tarjeta) ← dónde vive el dinero\n├─ Categoría / Subcategoría ← clasificación operativa\n├─ Cuenta contable (plan) ← resuelta por mapeo dinámico\n└─ Cliente/CC (opcional en ingresos) ← cobro sin duplicar ingreso",
        'flow' => 'Completar datos → Guardar → Confirmación compacta (cuenta acreditada/debitada) → posted.',
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
        'summary' => 'Cajas, bancos, billeteras y tarjetas donde vive el dinero.',
        'bullets' => [
            'Tipos: Efectivo, Banco, Billetera, Tarjeta, Otra.',
            'CBU/CVU = 22 dígitos; CUIT uniforme (validador central).',
            'Tarjetas: número con Luhn (solo se guarda last4), vencimiento; nunca CVV/CVC ni PAN.',
            'Por defecto solo se listan activas (sin columna Estado); usá «Ver inactivas».',
            'Los saldos se recalculan desde movimientos confirmados.',
        ],
    ],
    'categories' => [
        'title' => 'Categorías financieras',
        'summary' => 'Clasificación operativa expandible con analítica (totales, promedio, mensual, click-through).',
        'bullets' => [
            'Categoría ≠ cuenta financiera (caja/banco) y ≠ cuenta del plan contable por sí sola.',
            'Expandí una categoría para ver subcategorías y totales del período.',
            'Detalle: fechas, ámbito, cuenta financiera, búsqueda y paginación (30; no hay límite silencioso de 10).',
        ],
        'diagram' => "Dimensiones de un movimiento\n[Fecha] [Ámbito] [Descripción]\n[Categoría → Subcategoría] → mapea a [Cuenta contable]\n[Importe] en [Cuenta financiera]",
        'flow' => 'Listado expandible → clic categoría/sub → filtrá período/ámbito/FA → movimientos.',
    ],
    'exchange_rates' => [
        'title' => 'Cotizaciones',
        'summary' => 'USD/ARS oficial: vigente (DolarAPI) e histórico (ArgentinaDatos / importación).',
        'bullets' => [
            'Gráfico: eje X = fechas, Y = ARS/USD; leyenda compra/venta; tooltip con fecha/compra/venta/fuente.',
            'DolarAPI actualiza la cotización vigente sin borrar el histórico.',
            'Backfill ArgentinaDatos es idempotente; no inventa fines de semana.',
            'Los movimientos congelan la cotización de su fecha; no se recalculan en silencio.',
        ],
        'flow' => 'Filtrá rango → preview backfill → confirmar → el histórico queda disponible para valuación.',
    ],
    'clients_cc_opening' => [
        'title' => 'Establecer saldo de apertura',
        'summary' => 'Saldo inicial auditado (AJUSTE/APERTURA) sin borrar movimientos previos ni exigir comprobante.',
        'bullets' => [
            'Vista previa: positivo = A cobrar (nos deben); negativo = A favor del cliente.',
            'Opcional: control_cc_desde para acotar el timeline.',
            'Tras un reset comercial los saldos parten en 0; la apertura es explícita.',
            'Requiere permiso de regularización.',
        ],
    ],
    'equipment_sale' => [
        'title' => 'Venta de equipos',
        'summary' => 'Vender un equipo armado con margen % sobre costo, vía Ventas (sin módulo duplicado).',
        'bullets' => [
            'Precio sugerido = costo histórico × (1 + margen%).',
            'No re-consume componentes al vender.',
            'Detalle en docs/venta-de-equipos.md.',
        ],
        'flow' => 'Nueva venta → ítem Equipo → margen → borrador → confirmar.',
    ],
    'clients' => [
        'title' => 'Clientes',
        'summary' => 'Maestro de clientes y su cuenta corriente.',
        'bullets' => [
            'Particular: DNI obligatorio; CUIT opcional (validador central 11 dígitos + DV).',
            'Empresa: CUIT y razón social obligatorios (mismo validador que proveedores/cuentas).',
            'La condición fiscal es un catálogo (sin lógica ARCA todavía).',
            'Apertura de CC y ajustes quedan auditados.',
        ],
        'diagram' => "Ingreso + Aplicar a CC\n→ 1 movimiento financiero (crédito)\n→ aplicaciones a cargos / pago a cuenta\n(nunca 2 ingresos por el mismo cobro)",
        'flow' => 'Convención CC (presentación): + rojo = nos deben; − verde = a favor del cliente.',
    ],
    'clients_cc' => [
        'title' => 'Cuentas corrientes de clientes',
        'summary' => 'Ranking de saldos a cobrar / a favor, con Top deudores.',
        'bullets' => [
            'Saldo positivo (rojo): el cliente nos debe.',
            'Saldo negativo (verde): crédito a favor del cliente.',
            'Cobros, cargos y apertura manual están disponibles según permisos.',
        ],
        'flow' => 'Filtrá → ordená el ranking → abrí la ficha del cliente para el detalle de movimientos.',
    ],
    'suppliers' => [
        'title' => 'Proveedores',
        'summary' => 'Maestro de proveedores y su cuenta corriente.',
        'bullets' => [
            'Misma lógica Particular / Empresa que clientes.',
            'CUIT: mismo validador central (11 dígitos + dígito verificador).',
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
    'stock' => [
        'title' => 'Stock / Unidades',
        'summary' => 'Existencias físicas y unidades trackeadas (acceso desde Productos, no menú primario).',
        'bullets' => [
            'Los ingresos normales vienen de Compras.',
            'Los consumos usan FIFO y no deben editarse a mano el saldo.',
            'Ajustes, reservas y consumos se operan desde la ficha del producto.',
            'tracks_units: unidades individuales en /stock/unidades.',
        ],
        'flow' => 'Productos → clic en Stock o «Ver unidades» → operar desde la ficha.',
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
        'summary' => 'Cargos recurrentes a clientes (CHARGE), no ingresos de caja.',
        'bullets' => [
            'La generación periódica crea un cargo en CC (aumenta deuda), no un ingreso financiero.',
            'El dinero entra recién al cobrar (recibo / carga rápida con Aplicar a CC).',
            'No mueve stock por sí sola.',
            'Podés crear el primer abono desde cero desde el vacío.',
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
    'chart_accounts' => [
        'title' => 'Plan de cuentas',
        'summary' => 'Jerarquía contable (Activo/Pasivo/…); distinta de categorías, cajas y CC.',
        'bullets' => [
            'Árbol con totales reales de movimientos posted.',
            'Alerta roja si hay movimientos sin cuenta → asistente de mapeo.',
            'Alta/edición con padre, tipo, impacto y eliminación real (reasignar / sin asignar / cancelar).',
            'Documentación: docs/plan-de-cuentas.md.',
        ],
        'diagram' => "Movimiento → Cat/Sub → Mapeo → Cuenta contable → Reportes\nCuenta financiera ≠ Categoría ≠ Plan ≠ CC",
    ],
    'chart_accounts.mapping' => [
        'title' => 'Mapeo categorías → plan',
        'summary' => 'Reglas dinámicas con precedencia subcategoría > categoría > tipo > sin asignar.',
        'bullets' => [
            'Preferí mapear reglas; no reescribas cientos de movimientos a mano.',
            'Materializá históricos solo con preview + auditoría + confirmar aplicar.',
            'Podés crear una cuenta contable inline y asignarla de inmediato.',
            'No recalcula FX congelado al aplicar mapeo.',
        ],
        'flow' => 'Asignar reglas → Vista previa → Revisar muestra → Confirmar aplicar.',
    ],
    'movements' => [
        'title' => 'Movimientos',
        'summary' => 'Historial de ingresos, egresos y transferencias entre cuentas.',
        'bullets' => [
            'Columnas: Fecha | Cuenta financiera | Descripción | Ámbito | Cuenta contable | Importe.',
            'Cuenta financiera = caja/banco; cuenta contable = plan; categoría bajo la descripción.',
            'Colores semánticos en el importe (sin cambiar signos guardados).',
            'Un movimiento anulado deja de afectar saldos.',
        ],
        'flow' => 'Consultá el listado → abrí el detalle → usá carga rápida para nuevos movimientos.',
    ],
    'products' => [
        'title' => 'Productos',
        'summary' => 'Catálogo de ítems físicos o de servicio (entrada principal de Inventario).',
        'bullets' => [
            'Columnas: SKU | Familia | Nombre | Stock | Precio | acciones.',
            'Precio de venta: hoy no hay sale_price (no se muestra el costo como precio).',
            'Stock clickeable → unidades; «Ver todas las unidades» en el listado.',
            'Baja masiva con motivo: elimina si no hay relaciones; si hay, archiva.',
        ],
    ],
    'equipment' => [
        'title' => 'Equipos',
        'summary' => 'Equipos armados/serializados y su ciclo de vida.',
        'bullets' => [
            'Distinto de un producto genérico de stock.',
            'Podés armar desde plantillas, ver componentes, seriales y estado.',
            'Un equipo vendido no vuelve a ofrecerse; no re-consume componentes.',
            'Venta con margen: docs/venta-de-equipos.md.',
        ],
    ],
    'sales' => [
        'title' => 'Ventas',
        'summary' => 'Confirmación comercial con impacto en stock, CC y/o caja.',
        'bullets' => [
            'Crédito: baja stock y aumenta deuda del cliente, sin ingreso bancario.',
            'Contado: baja stock e ingresa dinero; la CC no queda con deuda.',
            'Equipo: margen % sobre costo; no re-consume componentes.',
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
