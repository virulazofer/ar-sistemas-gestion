# Pendientes de carga (requerimiento posterior a 11E)

**Estado:** documentado — **no implementar** en el alcance actual de 11E (preview histórico).  
**Origen:** aclaración sobre filas rojas del preview de `GASTOS MENSUALES 2026.xlsx` (anotaciones incompletas del usuario).

## Problema

En la planilla histórica aparecen filas que el usuario dejó a propósito como recordatorio (concepto/notas sin fecha y/o sin importe). No son basura ni errores de importación.

En preview 11E ya se clasifican esas filas (sin fecha e importe 0) como **Pendiente de completar**: no generan movimiento financiero, no afectan conciliación y no se eliminan.

## Función deseada (etapa posterior)

Permitir **anotaciones rápidas incompletas** (“Pendientes de carga”), por ejemplo:

- Falta seguro del auto
- Spotify
- YouTube
- Meli

Pueden comenzar solo con **concepto / notas**.

### Completar después

El usuario puede completar:

- fecha
- importe
- cuenta
- categoría / subcategoría
- ámbito Personal / Profesional
- contraparte (si corresponde)
- moneda
- observaciones

### Conversión a movimiento real

Al completarse y **confirmarse**, la anotación puede convertirse en un movimiento financiero real.

### Trazabilidad

Debe conservarse vínculo explícito entre:

1. el pendiente original (id / traza de origen), y  
2. el movimiento generado.

## Fuera de alcance 11E

- UI de creación/edición de pendientes
- Persistencia de pendientes fuera del preview histórico
- Flujo de conversión a movimiento confirmado
- Notificaciones / lista operativa de pendientes

Cuando se implemente, tratarlo como feature de finanzas/movimientos (o módulo dedicado), no como ampliación silenciosa del importador histórico.
