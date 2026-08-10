# Clientes y cuentas corrientes (Etapa 3)

## Convención de saldo en DB (perspectiva cliente)

| Valor `signed_amount` | Significado |
|-------|-------------|
| Negativo | El cliente debe |
| Positivo | Crédito a favor del cliente |
| Cero | Sin saldo |

Saldos **ARS** y **USD** son independientes. La equivalencia FX es informativa en el asiento; **nunca** convierte/mezcla saldos.

## Presentación UI (11F-1) — semántica invertida en pantalla

En ranking CC, dashboards y detalle, el **saldo de presentación** es `-signed_amount`:

| Saldo UI | Significado | Color |
|----------|-------------|-------|
| Positivo | Nos deben (a cobrar) | Rojo (atención) |
| Cero | Saldado | Neutro |
| Negativo | A favor del cliente | Verde (favorable) |

Resultados económicos/financieros siguen: + verde, − rojo, 0 neutro. Helper: `App\Support\UiSemantics`.

**Antigüedad:** omitida en el ranking. No hay aging fiable (cobros no se aplican FIFO a cargos individuales).

Ruta ranking: `/clientes/cuentas-corrientes` (`clients.current-accounts`).

## Tipos de movimiento CC

| Tipo | Efecto saldo | ¿Mueve finanzas? |
|------|--------------|------------------|
| `charge` (cargo) | − | No |
| `payment` (pago) | + | Sí (ingreso atómico) |
| `credit` (crédito a favor) | + | No |
| `credit_application` | − | No |
| `adjustment` | ± (explícito) | No |

Estados: `posted` | `voided`.

## Pago atómico

`ClientLedgerService::registerPayment`:

1. crea ingreso financiero (`MovementService`) con `client_id`;
2. crea asiento CC `payment` con `financial_movement_id`;
3. audita el vínculo;
4. todo en una transacción DB (rollback total ante error).

## Anulación

Anular un asiento CC con vínculo financiero también anula el movimiento financiero asociado.

## Documentos

Tabla `documents` polimórfica (adjuntos básicos a cliente).

## Futuro

Columnas preparadas: `invoice_id`, `quote_id`, `work_order_id`, `subscription_id`, `event_id`, `document_id`.
