# Plan de cuentas — guía de uso

El Plan de cuentas responde una sola pregunta: **¿qué fue la operación?**  
No es la cuenta del banco ni la tarjeta: eso es la **cuenta financiera** (¿dónde entró o salió el dinero?).

## Las cinco raíces

| Código | Nombre | En pocas palabras |
|--------|--------|-------------------|
| 1 | ACTIVO | Bienes, dinero y derechos con valor económico |
| 2 | PASIVO | Deudas y obligaciones pendientes |
| 3 | PATRIMONIO NETO | Diferencia simplificada entre lo que se posee y lo que se debe, más aportes y resultados |
| 4 | INGRESOS | Entradas económicas clasificadas |
| 5 | EGRESOS | Gastos clasificados |

Las raíces no se eliminan, no se mueven y no cambian de naturaleza. Debajo de ellas sí podés crear, editar, mover y desactivar subcuentas.

## Activo, Pasivo, Patrimonio y Créditos

- **Activo → Disponibilidades**: se alimenta de las cuentas financieras (caja, bancos, billeteras). No hace falta cargar un asiento aparte.
- **Activo → Créditos → Clientes**: muestra cuánto te deben (total a cobrar + ranking). El rojo indica “nos deben”. Click en un cliente abre su ficha / CC. No duplica la cuenta corriente.
- **Activo → Bienes de cambio**: preparado para valuación FIFO; si aún no hay método fiable verás *Valuación de inventario no disponible* (no un $0 engañoso).
- **Activo → Bienes de uso**: estructura de equipos, MYU, instrumentos, propiedades, vehículos, etc.
- **Pasivo**: tarjetas se derivan de cuentas financieras tipo tarjeta cuando hay datos; proveedores muestran *Sin datos suficientes* si no hay operatoria fiable.
- **Patrimonio Neto**: ayuda contextual; no inventamos saldos ni pedimos asientos avanzados para usar el sistema día a día.

## Plan vs cuenta financiera

| | Plan de cuentas | Cuenta financiera |
|--|-----------------|-------------------|
| Pregunta | ¿Qué fue? | ¿Dónde entró/salió? |
| Ejemplo | Automotor › Combustible | Mercado Pago |
| Quién elige | Vos al clasificar | Vos al elegir medio |
| Ubicación contable FA | Automática por tipo (banco→Bancos, billetera→Billeteras, efectivo→Caja, tarjeta→Pasivo/Tarjetas) | |

## Ámbito / Origen

**Egresos:** Personal · Profesional · Mixto  
**Ingresos:** Profesional · Financiero  

No hay “ingreso personal” en la carga normal. Mixto se acepta sin exigir reparto porcentual (el split queda para más adelante).

## Cómo usar la pantalla

1. Abrí **Plan de cuentas**.
2. A la derecha ves la **radiografía** de las cinco raíces del período.
3. Elegí período: Este mes / Mes anterior / Este año / Personalizado.
4. Click en una cuenta del árbol (izquierda): el panel derecho muestra total, gráfico si es padre, y movimientos (Fecha · Descripción · Cuenta financiera · Ámbito · Importe).
5. En el celular: navegación por niveles (raíz → grupo → hoja), no el árbol comprimido.

Los totales de padres **incluyen** a sus subcuentas.

## Crear / mover / eliminar

- **Crear:** desde una cuenta → *+ Subcuenta*. El sistema sugiere el próximo código (ej. bajo 5.3 → 5.3.4).
- **Editar / mover:** cambiar nombre, ayuda, código o padre compatible. No se permiten ciclos ni alterar las cinco raíces.
- **Eliminar vacía:** confirmación y listo.
- **Eliminar con movimientos:** reasignación obligatoria a otra cuenta. No existe “dejar sin clasificar”.

## Aprendizaje de clasificaciones

Al cargar un egreso/ingreso con descripción + clasificación:

1. Primera vez → el sistema pregunta *¿Recordar esta clasificación?*
2. Si decís que sí, la próxima vez con el mismo texto **autocompleta** plan + ámbito (no la cuenta financiera).
3. Textos parecidos (ej. “Piatto Rosso Devoto”) solo **sugieren**, no asumen certeza.
4. Si cambiás una clasificación ya recordada → pregunta si actualizar la memoria o “solo esta vez”.
5. *Dejar de recordar* desactiva esa memoria.
6. Listado en Plan → Configuración avanzada → Clasificaciones recordadas.

La clasificación manual siempre gana: nunca se sobrescribe en silencio.

## Colores (rojo / verde)

- **Rojo:** requiere atención (ej. cliente que nos debe, pasivo exigible).
- **Verde:** favorable (ingreso, activo, saldo a favor).
- **Neutro:** egresos cotidianos (un gasto normal no es alarma).

En cuenta corriente de clientes: **nos deben = rojo**, **a favor = verde**.

## Navegación

Uso diario: **Plan de cuentas** + **Cuentas financieras**.  
Pendientes de clasificación solo aparecen como alerta si hay movimientos sin clasificar.  
Asignación / reglas técnicas viven en **Configuración avanzada**, no en el menú principal.

## Ejemplos

**Gasto:** 13/08/2026 · Profesional · Nafta Shell · Automotor › Combustible · $50.000 · Mercado Pago  

**Ingreso:** 13/08/2026 · Profesional · Abono DAASA · Servicios profesionales › Abonos · $500.000 · Banco Patagonia · Cliente DAASA  

El sistema comercial/CC hace lo que corresponda; no hace falta una segunda carga contable.
