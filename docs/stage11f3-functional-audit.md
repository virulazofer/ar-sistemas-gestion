# Etapa 11F-3 — Auditoría funcional (solo documentación)

> Documento de requisitos. **No implementar en 11F-2.** Cobros y Regularización CC quedan fuera del alcance actual.

## Objetivo

Definir el comportamiento esperado de **Cobros** y **Regularización de cuenta corriente (CC)** antes de la implementación en 11F-3.

## Entradas de usuario (crítico)

Debe existir al menos una de estas rutas de acceso:

1. **Comercial → Cobros** (módulo / listado de cobros).
2. **Cliente → Cuenta corriente → Registrar cobro** (acción contextual sobre un cliente).

Ambas deben converger en la misma lógica de negocio y auditoría.

## Flujo: deuda insuficiente al cobrar

Al intentar registrar un cobro cuyo importe supera la deuda abierta (o no hay cargos pendientes suficientes), el sistema **debe presentar opciones explícitas** y **nunca inventar una factura automáticamente**:

| Opción | Comportamiento |
|--------|----------------|
| **A) Crear cargo faltante y luego cobrar** | Generar el movimiento/cargo omitido (con trazabilidad), y aplicar el cobro sobre esa deuda. |
| **B) Pago a cuenta** | Registrar el excedente como saldo a favor del cliente (CC negativa / favorable en convención de presentación). |
| **C) Cancelar** | Abortar sin cambios. |

**Prohibido:** auto-crear factura/comprobante inventado para “cuadrar” el cobro sin decisión del usuario.

## Regularizar CC (usuarios autorizados)

Acción **REGULARIZAR CC** disponible solo para perfiles con permiso específico (a definir en 11F-3).

Casos de uso típicos:

- Cargo omitido
- Cobro omitido
- Aplicación incorrecta de un cobro
- Saldo de apertura
- Corrección histórica puntual

### Reglas

- Toda regularización se hace mediante **movimientos auditados** (quién, cuándo, motivo, importes, referencias).
- **Nunca** editar el saldo de CC en forma directa (UPDATE a columna/balance).
- Debe quedar historial consultable en auditoría y en el mayor de CC del cliente.

## Convención visual CC (recordatorio 11F-1 / 11F-2)

Perspectiva de presentación “a cobrar” (negocio):

| Saldo mostrado | Significado | Color |
|----------------|-------------|--------|
| **+** (positivo) | El cliente **nos debe** | Rojo (atención) |
| **−** (negativo) | Saldo a favor del cliente / crédito | Verde (favorable) |
| **0** | Neutro | Neutro / muted |

Los colores semánticos (`UiSemantics` / tokens KPI) son independientes de la paleta de marca (skins).

## Fuera de alcance de este documento

- Detalle de pantallas, APIs y migraciones (se define al iniciar 11F-3).
- Importación histórica y recálculos financieros masivos.
- Cambios a ranking Top 5 / dashboard de gestión (preservar 11F-1).

## Criterio de aceptación previo a código (11F-3)

1. Flujos A/B/C documentados y validados con negocio.
2. Matriz de permisos para Cobros y Regularizar CC.
3. Casos de prueba: deuda exacta, deuda insuficiente (A/B/C), pago a cuenta, regularización auditada, intento de edición directa de saldo rechazado.
