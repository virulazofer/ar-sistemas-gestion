<?php

namespace App\Services\Suppliers;

use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierCodeService
{
    public const PREFIX = 'P';

    public function format(?int $code): string
    {
        if ($code === null || $code <= 0) {
            return '—';
        }

        return self::PREFIX.sprintf('%03d', $code);
    }

    public function label(Supplier $supplier): string
    {
        return $this->format($supplier->code).' — '.$supplier->name;
    }

    /**
     * Parse user input like "P001", "p1", "001", "1" into a positive int, or null.
     */
    public function parse(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^[Pp]\s*0*([0-9]+)$/', $s, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        if (ctype_digit($s)) {
            if ($s === '0' || preg_match('/^0+$/', $s)) {
                return null;
            }
            $n = (int) ltrim($s, '0');

            return $n > 0 ? $n : null;
        }

        return null;
    }

    public function allocateNext(): int
    {
        return (int) DB::transaction(function () {
            $next = (int) Setting::getValue('suppliers.next_code', 0);
            $max = (int) Supplier::query()->max('code');
            if ($next <= 0) {
                $next = $max > 0 ? $max + 1 : 1;
            }
            if ($max > 0 && $next <= $max) {
                $next = $max + 1;
            }

            Setting::setValue('suppliers.next_code', $next + 1, 'int');

            return $next;
        });
    }

    public function syncNextFromMax(): int
    {
        $max = (int) Supplier::query()->max('code');
        $next = $max > 0 ? $max + 1 : 1;
        Setting::setValue('suppliers.next_code', $next, 'int');

        return $next;
    }

    public function assertEditable(?int $currentCode, mixed $incoming, bool $canEditCode): ?int
    {
        if ($incoming === null || $incoming === '') {
            return $currentCode;
        }

        $code = $this->parse($incoming) ?? (is_numeric($incoming) ? (int) $incoming : 0);
        if ($code <= 0) {
            throw new InvalidArgumentException('El código de proveedor debe ser un entero positivo (ej. P001).');
        }

        if ($currentCode !== null && $code !== $currentCode && ! $canEditCode) {
            throw new InvalidArgumentException('El código de proveedor es inmutable salvo permiso administrativo.');
        }

        return $code;
    }
}
