# Plan de cuentas (ETAPA 11F — modelo único)

Clasificación económica/contable central de AR Sistemas. **Un solo árbol jerárquico.**

## Dimensiones de una operación

```
OPERACIÓN
│
├── ¿Qué ocurrió?
│      PLAN DE CUENTAS
│
├── ¿Dónde entró/salió?
│      CUENTA FINANCIERA
│
├── ¿Qué ámbito/origen tiene?
│      EGRESO: Personal / Profesional / Mixto
│      INGRESO: Profesional / Financiero
│
└── ¿Con quién?
       CLIENTE / PROVEEDOR (cuando corresponda)
```

| Dimensión | Modelo | Pregunta |
|-----------|--------|----------|
| Plan de cuentas | `chart_accounts` | ¿Qué fue? |
| Cuenta financiera | `financial_accounts` | ¿Dónde entró/salió el dinero? |
| Ámbito / Origen | `movements.scope` | Personal/Profesional/Mixto o Profesional/Financiero |
| Cliente / Proveedor | maestros CC | ¿Con quién? |
| Fecha / Importe | movimiento | ¿Cuándo / cuánto? |

**No** duplicar cuentas del plan por ámbito. Impuestos viven bajo Activo / Pasivo / Egresos (sin 6.ª raíz).

## Cinco raíces protegidas

1 ACTIVO · 2 PASIVO · 3 PATRIMONIO NETO · 4 INGRESOS · 5 EGRESOS

No se eliminan, no se mueven, no cambian de naturaleza. El **código visible ≠ id** de base.

## Ámbito / Origen

- **Egresos:** Personal | Profesional | Mixto  
- **Ingresos (nueva carga):** Profesional | Financiero (no Personal/Mixto)  
- Históricos Ingreso+Personal: **no** convertir en silencio → dry-run

## Cuentas financieras → ubicación contable

Automático por tipo (sin mapeo por movimiento):

| Tipo FA | Ubicación plan |
|---------|----------------|
| Efectivo | 1.1.1 Caja / Efectivo |
| Banco | 1.1.2 Bancos |
| Billetera | 1.1.3 Billeteras virtuales |
| Tarjeta | 2.1 Tarjetas de crédito |

## Compatibilidad

Tablas `categories` / `subcategories` se mantienen en migración progresiva (dual-read/dual-write). La UX cotidiana usa el plan (Concepto). Menú bajo Plan: **Ver plan · Pendientes de clasificación (N si >0) · Asignación al plan · Reglas automáticas**.

## Dry-run / Apply Fase 1

```bash
php artisan chart:dry-run-11f --json=exports/11f/dry-run.json
# Solo infraestructura (árbol + link FA + remap masters; SIN movimientos):
php artisan chart:dry-run-11f --infra
# Apply autorizado Fase 1 (2B raíces legacy + FA + Bazar/MUBI + convergencia chart):
php artisan chart:apply-11f --confirm=APPLY-11F-PHASE1 --json=exports/11f/apply-phase1.json
```

**Apply masivo solo con aprobación explícita.** Tras Fase 1: **DETENERSE** (no Fase 2 / Etapa 12).

### Addendum 2B — Bienes de uso

Raíces legacy (`Instrumentos musicales`, `Propiedades`, `Vehículos`) se reubican bajo `1.5 Bienes de uso` (sin duplicar; se preservan IDs/movimientos):

`1.5.1 Equipamiento` · `1.5.2 Muebles y útiles` · `1.5.3 Instrumentos musicales` · `1.5.4 Propiedades` · `1.5.5 Vehículos` · `1.5.6 Otros bienes de uso`

## Ayuda breve

- **Créditos:** dinero que terceros le deben al negocio (p. ej. saldos de clientes). Detalle operativo = maestro Clientes/CC; el plan agrega en 1.2.1.  
- **Patrimonio Neto:** diferencia entre lo que el negocio posee y lo que debe (capital, aportes, resultados).

## Ejemplos A–H

### A. Compra combustible con Mercado Pago
- Usuario: Egreso · Ámbito Profesional · Concepto Automotor › Combustible · FA Mercado Pago  
- Plan: 5.3.1 · FA ubicación: 1.1.3 Billeteras · CC: no

### B. Pago VISA desde Patagonia
- Usuario: Transferencia Patagonia → VISA  
- Plan económico del gasto ya quedó al consumir; este flujo mueve disponibilidad (1.1.2) vs pasivo tarjeta (2.1)  
- No duplica egreso

### C. Venta equipo cobrada por Patagonia
- Usuario: Ingreso · Origen Profesional · Concepto Ventas › Equipos · FA Patagonia · Cliente opcional  
- Plan: 4.1.1 · FA: 1.1.2

### D. Venta a DAASA a cuenta corriente
- Circuito comercial: cargo CC (aumenta crédito clientes) · **no** inventa caja  
- Agregado contable: 1.2.1 Clientes (detalle en ficha DAASA)

### E. Cobro posterior de DAASA
- Cobro CC + FA · un solo ingreso financiero · aplica cargos  
- Plan según concepto del cobro/ingreso; FA cambia; CC baja

### F. Intereses acreditados por Mercado Pago
- Ingreso · Origen Financiero · Concepto Ingresos financieros › Intereses · FA MP  
- Plan: 4.3.1

### G. Gasto personal de supermercado
- Egreso · Personal · Alimentación › Supermercado · FA caja/billetera  
- Plan: 5.1.1

### H. Gasto mixto de Internet
- Egreso · Mixto · Servicios › Internet · FA correspondiente  
- Plan: 5.2.3 · ámbito Mixto analítico (sin cuenta duplicada)

## Migración

1. Auditar · 2. Seed raíces/árbol · 3. Nueva UX · 4. Dry-run · 5. Tests · 6. Deploy código  
**Apply masivo de datos: solo con aprobación explícita del usuario.**
