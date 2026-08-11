<?php

namespace App\Support;

/**
 * Preferencias de apariencia (11F-2): MODE × PALETTE.
 * Semántica (success/danger/kpi) es independiente de la paleta de marca.
 */
final class Appearance
{
    public const MODE_LIGHT = 'light';

    public const MODE_DARK = 'dark';

    public const PALETTE_ACTUAL = 'actual';

    public const PALETTE_AZUL = 'azul';

    public const PALETTE_VERDE = 'verde';

    public const PALETTE_CALIDA = 'calida';

    public const PALETTE_GRIS = 'gris';

    public const PALETTE_MULTICOLOR = 'multicolor';

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [self::MODE_LIGHT, self::MODE_DARK];
    }

    /**
     * @return list<string>
     */
    public static function palettes(): array
    {
        return [
            self::PALETTE_ACTUAL,
            self::PALETTE_AZUL,
            self::PALETTE_VERDE,
            self::PALETTE_CALIDA,
            self::PALETTE_GRIS,
            self::PALETTE_MULTICOLOR,
        ];
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_DARK => 'Oscuro',
            default => 'Claro',
        };
    }

    public static function paletteLabel(string $palette): string
    {
        return match ($palette) {
            self::PALETTE_AZUL => 'Azul',
            self::PALETTE_VERDE => 'Verde',
            self::PALETTE_CALIDA => 'Cálida',
            self::PALETTE_GRIS => 'Gris',
            self::PALETTE_MULTICOLOR => 'Multicolor',
            default => 'Actual',
        };
    }

    public static function normalizePalette(?string $palette): string
    {
        $palette = strtolower(trim((string) $palette));

        return in_array($palette, self::palettes(), true) ? $palette : self::PALETTE_ACTUAL;
    }

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_DARK ? self::MODE_DARK : self::MODE_LIGHT;
    }
}
