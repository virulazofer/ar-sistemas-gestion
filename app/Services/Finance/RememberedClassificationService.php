<?php

namespace App\Services\Finance;

use App\Models\ChartAccount;
use App\Models\RememberedClassification;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Aprendizaje de clasificaciones por descripción → plan + ámbito.
 * NO memoriza cuenta financiera.
 */
class RememberedClassificationService
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_PROBABLE = 'probable';

    public function __construct(private readonly AuditLogger $audit) {}

    public function normalize(string $description): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $description) ?? ''));
        $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $s);

        return mb_substr($s, 0, 255);
    }

    /**
     * @return array{match: ?string, memory: ?RememberedClassification, suggested_chart_account_id: ?int, suggested_scope: ?string, path: ?string}|null
     */
    public function lookup(string $description, string $movementType, bool $touch = true): ?array
    {
        $normalized = $this->normalize($description);
        if ($normalized === '' || ! in_array($movementType, ['income', 'expense'], true)) {
            return null;
        }

        $exact = RememberedClassification::query()
            ->active()
            ->with('chartAccount')
            ->where('movement_type', $movementType)
            ->where('pattern_normalized', $normalized)
            ->first();

        if ($exact) {
            if ($touch) {
                $exact->forceFill([
                    'hit_count' => $exact->hit_count + 1,
                    'last_used_at' => now(),
                ])->saveQuietly();
            }

            return $this->payload(self::MATCH_EXACT, $exact);
        }

        // Cargar candidatas activas del tipo y filtrar en PHP (portable SQLite/MySQL).
        $candidates = RememberedClassification::query()
            ->active()
            ->with('chartAccount')
            ->where('movement_type', $movementType)
            ->orderByDesc('hit_count')
            ->limit(200)
            ->get()
            ->filter(function (RememberedClassification $c) use ($normalized) {
                $p = $c->pattern_normalized;
                if ($p === '' || $p === $normalized) {
                    return false;
                }

                return str_contains($normalized, $p) || str_contains($p, $normalized);
            })
            ->sortByDesc(fn (RememberedClassification $c) => mb_strlen($c->pattern_normalized))
            ->take(8)
            ->values();

        $best = $this->bestProbable($normalized, $candidates);
        if (! $best) {
            return null;
        }

        return $this->payload(self::MATCH_PROBABLE, $best);
    }

    /**
     * @param  Collection<int, RememberedClassification>  $candidates
     */
    private function bestProbable(string $normalized, Collection $candidates): ?RememberedClassification
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $c) {
            $p = $c->pattern_normalized;
            if ($p === '' || $p === $normalized) {
                continue;
            }
            $contained = str_contains($normalized, $p) || str_contains($p, $normalized);
            if (! $contained) {
                continue;
            }
            $shorter = min(mb_strlen($p), mb_strlen($normalized));
            $longer = max(mb_strlen($p), mb_strlen($normalized));
            if ($longer === 0) {
                continue;
            }
            $score = $shorter / $longer;
            // Exigir solapamiento significativo para no sugerir basura.
            if ($score < 0.55 || $shorter < 4) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        return $best;
    }

    /**
     * @return array{match: string, memory: RememberedClassification, suggested_chart_account_id: int, suggested_scope: ?string, path: ?string}
     */
    private function payload(string $match, RememberedClassification $memory): array
    {
        $account = $memory->chartAccount;

        return [
            'match' => $match,
            'memory' => $memory,
            'suggested_chart_account_id' => (int) $memory->chart_account_id,
            'suggested_scope' => $memory->scope,
            'path' => $account?->pathLabel(),
        ];
    }

    public function remember(
        string $description,
        string $movementType,
        int $chartAccountId,
        ?string $scope,
        ?int $userId = null,
    ): RememberedClassification {
        $normalized = $this->normalize($description);
        if ($normalized === '') {
            throw new \InvalidArgumentException('La descripción está vacía; no se puede recordar.');
        }

        ChartAccount::query()->findOrFail($chartAccountId);
        $userId = $userId ?? Auth::id();

        $existing = RememberedClassification::query()
            ->where('pattern_normalized', $normalized)
            ->where('movement_type', $movementType)
            ->first();

        if ($existing) {
            $old = $existing->toArray();
            $existing->update([
                'pattern_display' => trim($description),
                'chart_account_id' => $chartAccountId,
                'scope' => $scope,
                'is_active' => true,
                'match_kind' => RememberedClassification::KIND_EXACT,
                'updated_by' => $userId,
                'last_used_at' => now(),
            ]);
            $this->audit->log(
                'remembered_classification_updated',
                $existing,
                $old,
                $existing->fresh()->toArray(),
                'Clasificación recordada actualizada'
            );

            return $existing->fresh(['chartAccount']);
        }

        $created = RememberedClassification::query()->create([
            'pattern_normalized' => $normalized,
            'pattern_display' => trim($description),
            'movement_type' => $movementType,
            'chart_account_id' => $chartAccountId,
            'scope' => $scope,
            'match_kind' => RememberedClassification::KIND_EXACT,
            'is_active' => true,
            'hit_count' => 0,
            'last_used_at' => now(),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->audit->log(
            'remembered_classification_created',
            $created,
            null,
            $created->toArray(),
            'Clasificación recordada creada'
        );

        return $created->load('chartAccount');
    }

    public function deactivate(RememberedClassification $memory, ?int $userId = null): void
    {
        $old = $memory->toArray();
        $memory->update([
            'is_active' => false,
            'updated_by' => $userId ?? Auth::id(),
        ]);
        $this->audit->log(
            'remembered_classification_deactivated',
            $memory,
            $old,
            $memory->fresh()->toArray(),
            'Dejar de recordar clasificación'
        );
    }

    public function forgetByDescription(string $description, string $movementType, ?int $userId = null): bool
    {
        $memory = RememberedClassification::query()
            ->where('pattern_normalized', $this->normalize($description))
            ->where('movement_type', $movementType)
            ->first();

        if (! $memory) {
            return false;
        }

        $this->deactivate($memory, $userId);

        return true;
    }

    public function delete(RememberedClassification $memory): void
    {
        $snapshot = $memory->toArray();
        $memory->delete();
        $this->audit->log(
            'remembered_classification_deleted',
            null,
            $snapshot,
            null,
            'Clasificación recordada eliminada'
        );
    }
}
