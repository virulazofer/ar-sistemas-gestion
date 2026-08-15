<?php

namespace App\Services\Documents;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DocumentCodeService
{
    /**
     * Asigna DOC-YYYY-NNNNNN inmutable. Llamar dentro de transacción DB.
     */
    public function allocate(Carbon|string|null $date = null): string
    {
        $year = $date
            ? Carbon::parse($date)->format('Y')
            : now()->format('Y');

        $key = 'documents.next_sequence.'.$year;

        $row = DB::table('settings')->where('key', $key)->lockForUpdate()->first();
        if (! $row) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => '1',
                'type' => 'int',
                'group' => 'documents',
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

        return sprintf('DOC-%s-%06d', $year, $seq);
    }

    public function peekNext(Carbon|string|null $date = null): string
    {
        $year = $date
            ? Carbon::parse($date)->format('Y')
            : now()->format('Y');
        $seq = max(1, (int) Setting::getValue('documents.next_sequence.'.$year, 1));

        return sprintf('DOC-%s-%06d', $year, $seq);
    }
}
