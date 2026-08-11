<?php

namespace App\Support;

/**
 * Etiquetas UI centralizadas (11F-2).
 * Traduce valores técnicos visibles sin alterar enums/DB.
 */
final class UiLabels
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'income' => 'Ingresos',
        'expense' => 'Egresos',
        'transfer' => 'Transferencia',
        'transfer_in' => 'Transferencia entrada',
        'transfer_out' => 'Transferencia salida',
        'asset' => 'Activos',
        'liability' => 'Pasivos',
        'equity' => 'Patrimonio',
        'result' => 'Resultados',
        'dashboard' => 'Tablero',
        'stock' => 'Stock',
        'posted' => 'Confirmado',
        'voided' => 'Anulado',
        'draft' => 'Borrador',
        'active' => 'Activo',
        'inactive' => 'Inactivo',
        'personal' => 'Personal',
        'professional' => 'Profesional',
        'cash' => 'Efectivo',
        'bank' => 'Banco',
        'wallet' => 'Billetera',
        'other' => 'Otra',
        'light' => 'Claro',
        'dark' => 'Oscuro',
        'profile' => 'Perfil',
        'register' => 'Registrarse',
    ];

    public static function get(?string $key, ?string $fallback = null): string
    {
        if ($key === null || $key === '') {
            return $fallback ?? '';
        }

        $normalized = strtolower(trim($key));

        return self::MAP[$normalized] ?? ($fallback ?? $key);
    }

    /**
     * @param  iterable<string|null>  $keys
     * @return list<string>
     */
    public static function many(iterable $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = self::get($key);
        }

        return $out;
    }
}
