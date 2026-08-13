<?php

return [
    'movements.quick' => [
        'title' => 'Carga de movimiento',
        'summary' => 'Registro rápido. Egreso: Ámbito P/P/M · Ingreso: Origen Profesional/Financiero · Concepto = hoja del plan.',
        'bullets' => [
            'Concepto busca en el plan (ej. «comb» → Automotor › Combustible).',
            'Ámbito/origen se sugiere según la cuenta pero siempre es editable.',
            'Ingreso: Cliente opcional + «Aplicar a CC» = un solo crédito financiero.',
            'Cuenta financiera = dónde entra/sale el dinero (distinta del plan).',
        ],
        'diagram' => "OPERACIÓN\n├─ Plan de cuentas (qué)\n├─ Cuenta financiera (dónde)\n├─ Ámbito/Origen (quién)\n└─ Cliente/Proveedor (cuando corresponda)",
        'flow' => 'Tipo → Fecha → Ámbito/Origen → Descripción → Concepto → Importe → Cuenta financiera → Guardar.',
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
        'title' => 'Categorías y subcategorías',
        'summary' => 'Clasificación operativa. Ámbito Personal/Profesional es independiente.',
        'bullets' => [
            'QUÉ ES: agrupación operativa de movimientos (no es caja ni plan contable).',
            'PARA QUÉ: filtrar, analizar y mapear hacia una cuenta contable.',
            'ACCIÓN: crear/renombrar/fusionar/reubicar con preview; no se bloquea por tener movimientos.',
            'Categoría ≠ cuenta financiera ≠ cuenta contable.',
        ],
        'diagram' => "Dimensiones de un movimiento\n[Fecha] [Ámbito] [Descripción]\n[Categoría → Subcategoría] → mapea a [Cuenta contable]\n[Importe] en [Cuenta financiera]",
        'flow' => 'Listado → detalle → reclasificar con preview → auditoría.',
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
        'summary' => 'Clasificación económica única (Activo/Pasivo/Patrimonio Neto/Ingresos/Egresos). Árbol a la izquierda, detalle a la derecha.',
        'bullets' => [
            'Cinco raíces protegidas; códigos visibles ≠ IDs.',
            'Créditos: dinero que terceros deben (detalle operativo en Clientes/CC).',
            'Patrimonio Neto: lo que el negocio posee menos lo que debe.',
            'Uso diario: Plan de cuentas + Cuentas financieras. Pendientes solo si hay alertas. Clasificaciones recordadas en Configuración avanzada.',
        ],
        'diagram' => "OPERACIÓN\n├─ Plan (qué) · FA (dónde) · Ámbito/Origen · Cliente/Proveedor",
    ],
    'chart_accounts.mapping' => [
        'title' => 'Vinculaciones contables',
        'summary' => 'Configuración avanzada. Vincula categoría/subcategoría con cuentas del plan. Precedencia: manual → sub → cat → regla → pendiente.',
        'bullets' => [
            'La cola diaria es «Pendientes de clasificación» (sin categoría), no exigir cuenta del plan redundante.',
            'Materializá históricos solo con vista previa: N coinciden · X manuales · Y cambiarían · Z intactos.',
            'Por defecto no se sobrescribe una clasificación ya confirmada.',
        ],
        'flow' => 'Asignar cat/sub → Vista previa → Revisar → Confirmar aplicar.',
    ],
    'chart_accounts.unclassified' => [
        'title' => 'Pendientes de clasificación',
        'summary' => 'Cola accionable: ingresos/egresos publicados sin categoría operativa.',
        'bullets' => [
            'QUÉ ES: solo movimientos sin categoría (category_id nulo).',
            'PARA QUÉ: clasificar uno a uno, masivo o por patrón con vista previa.',
            'ACCIÓN: filtrar → seleccionar → vista previa → confirmar → opcional guardar regla.',
            'Cat/sub correcta no cuenta como pendiente por falta de cuenta del plan.',
        ],
        'flow' => 'Alerta → Pendientes → patrón/fila → vista previa → aplicar.',
        'diagram' => "INGRESO|EGRESO → Categoría → Subcategoría\nÁmbito independiente · Plan opcional",
    ],
    'chart_accounts.assistant' => [
        'title' => 'Pendientes de clasificación (patrones)',
        'summary' => 'Agrupa pendientes por concepto/clasificación con confianza ALTA/MEDIA/BAJA.',
        'bullets' => [
            'ALTA: puede ofrecer masivo tras vista previa.',
            'MEDIA: requiere revisión humana.',
            'BAJA: no auto-aplicar.',
        ],
    ],
    'imputation_rules' => [
        'title' => 'Reglas de clasificación automática',
        'summary' => 'Opcionales. Condición → destino (categoría/sub/cuenta del plan). El sistema funciona con 0 reglas activas.',
        'bullets' => [
            'QUÉ ES: atajos reutilizables (ej. concepto contiene «Spotify»).',
            'PARA QUÉ: clasificar futuros y, con vista previa, históricos sin pisar manuales.',
            'ACCIÓN: crear → vista previa (N/X/Y/Z) → confirmar si corresponde.',
        ],
        'flow' => 'Crear → Vista previa → Confirmar → uso futuro.',
    ],
    'settings' => [
        'title' => 'Configuración',
        'summary' => 'Parámetros del sistema en castellano.',
        'bullets' => [
            'Labels y descripciones visibles en español.',
            'Claves técnicas internas pueden permanecer en inglés en base de datos.',
        ],
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
        'summary' => 'Consultas y exportaciones de finanzas, stock y comercial (UI en castellano).',
        'bullets' => [
            'QUÉ ES: vistas de Movimientos, Saldos, Ingresos/Egresos, CxC, etc.',
            'PARA QUÉ: control y exportación CSV/XLSX/PDF.',
            'ACCIÓN: filtrar → revisar columnas en español → exportar según permiso.',
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
        'title' => 'Usuarios',
        'summary' => 'Altas, roles, verificación de correo y restablecimiento de contraseña.',
        'bullets' => [
            'QUÉ ES: acceso al sistema (sin contraseñas en claro).',
            'PARA QUÉ: controlar permisos y recuperar acceso por email.',
            'ACCIÓN: crear usuario → verificación → «Enviar enlace para restablecer» si hace falta.',
            'SMTP pendiente: la función existe; revisá diagnóstico de correo en la ficha.',
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
