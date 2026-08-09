<?php

namespace App\Services\Imports\Historical;

use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ClientDetectionService
{
    /**
     * @param  list<string>  $names
     * @return array{
     *   clients: list<array{name:string,source:string,count:int}>,
     *   possible_duplicates: list<array{a:string,b:string,score:float}>,
     *   aliases: list<array{raw:string,canonical:string}>
     * }
     */
    public function detect(array $names): array
    {
        $known = config('historical_import.client_known_aliases', []);
        $counts = [];
        $aliases = [];

        foreach ($names as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $norm = $this->normalize($raw);
            $canonical = $known[$norm] ?? $this->titleCase($raw);
            if (isset($known[$norm]) && $known[$norm] !== $raw) {
                $aliases[] = ['raw' => $raw, 'canonical' => $canonical];
            }
            $counts[$canonical] = ($counts[$canonical] ?? 0) + 1;
        }

        $clients = [];
        foreach ($counts as $name => $count) {
            $clients[] = [
                'name' => $name,
                'source' => 'historical',
                'count' => $count,
                'exists' => Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(),
            ];
        }

        usort($clients, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'clients' => $clients,
            'possible_duplicates' => $this->findPossibleDuplicates(array_keys($counts)),
            'aliases' => $this->uniqueAliases($aliases),
        ];
    }

    /**
     * Extract possible client token from concepto like "DAASA - instalación AP".
     */
    public function extractFromConcept(?string $concepto, ?string $subcuenta = null): ?string
    {
        $concepto = trim((string) $concepto);
        $known = config('historical_import.client_known_aliases', []);

        if ($subcuenta) {
            $subNorm = $this->normalize($subcuenta);
            if (isset($known[$subNorm])) {
                return $known[$subNorm];
            }
            // Subcuenta that looks like a person/company (not a financial alias)
            $financial = array_change_key_case(config('historical_import.financial_aliases', []), CASE_LOWER);
            if (! isset($financial[mb_strtolower($subcuenta)]) && ! $this->looksLikeMonth($subcuenta)) {
                if (preg_match('/^[A-Za-zÁÉÍÓÚÑáéíóúñ0-9 ._-]{2,40}$/u', $subcuenta)) {
                    return $this->titleCase($subcuenta);
                }
            }
        }

        if ($concepto === '') {
            return null;
        }

        if (preg_match('/^([A-Za-zÁÉÍÓÚÑáéíóúñ0-9 ._-]{2,40})\s*[-–:]\s*.+/u', $concepto, $m)) {
            $token = trim($m[1]);
            $norm = $this->normalize($token);
            return $known[$norm] ?? $this->titleCase($token);
        }

        $normFull = $this->normalize($concepto);
        foreach ($known as $alias => $canonical) {
            if (str_contains($normFull, $alias)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @return list<array{a:string,b:string,score:float}>
     */
    private function findPossibleDuplicates(array $names): array
    {
        $out = [];
        $n = count($names);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $names[$i];
                $b = $names[$j];
                similar_text($this->normalize($a), $this->normalize($b), $pct);
                if ($pct >= 82 && $pct < 100) {
                    $out[] = ['a' => $a, 'b' => $b, 'score' => round($pct, 1)];
                }
            }
        }

        usort($out, fn ($x, $y) => $y['score'] <=> $x['score']);

        return array_slice($out, 0, 50);
    }

    /**
     * @param  list<array{raw:string,canonical:string}>  $aliases
     * @return list<array{raw:string,canonical:string}>
     */
    private function uniqueAliases(array $aliases): array
    {
        $seen = [];
        $out = [];
        foreach ($aliases as $row) {
            $key = $this->normalize($row['raw']).'|'.$this->normalize($row['canonical']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    public function normalize(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function titleCase(string $value): string
    {
        return Str::of($value)->trim()->title()->toString();
    }

    private function looksLikeMonth(string $value): bool
    {
        return (bool) preg_match('/^(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)$/iu', trim($value));
    }
}
