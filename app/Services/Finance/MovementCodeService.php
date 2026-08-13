<?php

namespace App\Services\Finance;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MovementCodeService
{
    /**
     * Asigna el siguiente código inmutable MOV-YYYY-NNNNNN (año de la fecha del movimiento).
     * Nunca reutiliza números. Debe llamarse dentro de una transacción DB.
     */
    public function allocate(Carbon|string|null $movementDate = null): string
    {
        $year = $movementDate
            ? Carbon::parse($movementDate)->format('Y')
            : now()->format('Y');

        $key = 'movements.next_sequence.'.$year;

        $row = DB::table('settings')->where('key', $key)->lockForUpdate()->first();
        if (! $row) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => '1',
                'type' => 'int',
                'group' => 'movements',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('settings')->where('key', $key)->lockForUpdate()->first();
        }

        $seq = max(1, (int) ($row->value ?? 1));

        DB::table('settings')->where('key', $key)->update([
            'value' => (string) ($seq + 1),
            'type' => 'int',
            'updated_at' => now(),
        ]);
        Cache::forget("setting.{$key}");

        return sprintf('MOV-%s-%06d', $year, $seq);
    }

    public function peekNext(Carbon|string|null $movementDate = null): string
    {
        $year = $movementDate
            ? Carbon::parse($movementDate)->format('Y')
            : now()->format('Y');
        $seq = max(1, (int) Setting::getValue('movements.next_sequence.'.$year, 1));

        return sprintf('MOV-%s-%06d', $year, $seq);
    }
}
