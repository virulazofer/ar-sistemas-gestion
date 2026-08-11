<?php

namespace App\Services\Imports\Historical;

/**
 * Clasifica ámbito Personal/Profesional según reglas aprobadas por el usuario.
 * No usa titular/cuenta financiera como determinante.
 */
class HistoricalScopeClassifier
{
    /**
     * @return array{
     *   scope: ?string,
     *   rule_id: ?string,
     *   rule_label: ?string,
     *   reason: ?string,
     *   precedence: ?string,
     *   override_allowed: bool,
     *   original_classification: string
     * }|null  null = no change from category default path
     */
    public function classify(
        ?int $sourceRow,
        string $concepto,
        string $cuenta,
        ?string $client = null,
        bool $wasAmbiguous = false,
    ): ?array {
        $norm = $this->normalize($concepto);
        $original = $wasAmbiguous ? 'ambito_dudoso' : 'categoria_default';

        // 1) Row override
        if ($sourceRow !== null) {
            $overrides = config('historical_scope_rules.row_overrides', []);
            if (isset($overrides[$sourceRow])) {
                $o = $overrides[$sourceRow];

                return $this->result(
                    (string) $o['scope'],
                    'row_override_'.$sourceRow,
                    'Decisión explícita por fila',
                    (string) ($o['reason'] ?? 'Override de usuario'),
                    '1_row_override',
                    $original
                );
            }
        }

        // 2) Known client / professional context (inequívoco)
        if ($this->mentionsKnownClient($norm) || $this->hasProfessionalContext($norm, $client)) {
            // Confitería Lidercar and similar
            if ($this->mentionsKnownClient($norm) || $this->isEnvioEquipo($norm)) {
                return $this->result(
                    'professional',
                    $this->isEnvioEquipo($norm) ? 'envio_equipo_cliente' : 'cliente_conocido_en_concepto',
                    $this->isEnvioEquipo($norm)
                        ? 'Envío/transporte de equipo asociado a cliente'
                        : 'Gasto que menciona cliente conocido',
                    'Contexto profesional inequívoco en concepto/contraparte',
                    '2_cliente_contexto',
                    $original
                );
            }
            if ($this->hasProfessionalContext($norm, $client)) {
                return $this->result(
                    'professional',
                    'contexto_profesional_inequivoco',
                    'Contexto profesional inequívoco',
                    'Keywords profesionales en concepto',
                    '2_cliente_contexto',
                    $original
                );
            }
        }

        // 3) Concept-specific (non-global Uber; peaje rules)
        if ($cuenta === 'Viaticos' || $cuenta === 'Comidas') {
            if ($this->isPeajeEstacionamiento($norm)) {
                // Professional if context; else personal
                if ($this->hasProfessionalContext($norm, $client) || $this->mentionsKnownClient($norm)) {
                    return $this->result(
                        'professional',
                        'viatico_peaje_contexto_profesional',
                        'Peaje/estacionamiento con contexto profesional',
                        'Asociado a cliente/visita/trabajo/envío',
                        '3_concepto_especifico',
                        $original
                    );
                }

                return $this->result(
                    'personal',
                    'viatico_peaje_sin_contexto_profesional',
                    'Peaje/AUSA/estacionamiento/Blinkay sin contexto profesional',
                    'Sin evidencia profesional → PERSONAL',
                    '3_concepto_especifico',
                    $original
                );
            }
        }

        if (preg_match('/chacharramendi/u', $norm) && ! preg_match('/\buber\b/u', $norm)) {
            return $this->result(
                'personal',
                'chacharramendi_personal',
                'Chacharramendi → PERSONAL',
                'Decisión de concepto (no regla Uber)',
                '3_concepto_especifico',
                $original
            );
        }

        // 4) Category rules
        if ($cuenta === 'Comidas') {
            // Professional exception already handled above; default personal
            return $this->result(
                'personal',
                'categoria_comidas_personal',
                'Categoría Comidas → PERSONAL',
                'Regla general; excepciones profesionales tienen prioridad',
                '4_categoria',
                $original
            );
        }

        // Viaticos without peaje pattern and without professional evidence → remain ambiguous
        if ($cuenta === 'Viaticos') {
            if ($this->hasProfessionalContext($norm, $client) || $this->mentionsKnownClient($norm) || $this->isEnvioEquipo($norm)) {
                return $this->result(
                    'professional',
                    'viatico_contexto_profesional',
                    'Viático con contexto profesional',
                    'Cliente/trabajo/envío detectado',
                    '2_cliente_contexto',
                    $original
                );
            }

            return null; // keep yellow ambiguous (e.g. Uber genérico sin override)
        }

        return null;
    }

    private function result(
        string $scope,
        string $ruleId,
        string $label,
        string $reason,
        string $precedence,
        string $original,
    ): array {
        return [
            'scope' => $scope,
            'rule_id' => $ruleId,
            'rule_label' => $label,
            'reason' => $reason,
            'precedence' => $precedence,
            'override_allowed' => true,
            'original_classification' => $original,
        ];
    }

    private function normalize(string $concepto): string
    {
        $c = mb_strtolower(trim($concepto));
        $c = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $c);

        return preg_replace('/\s+/', ' ', $c) ?? $c;
    }

    private function mentionsKnownClient(string $norm): bool
    {
        foreach (config('historical_scope_rules.known_clients', []) as $client) {
            $c = $this->normalize((string) $client);
            if ($c !== '' && str_contains($norm, $c)) {
                return true;
            }
        }

        return false;
    }

    private function hasProfessionalContext(string $norm, ?string $client): bool
    {
        if ($client !== null && $client !== '') {
            // extracted client name from concept — treat as professional signal for Comidas/Viaticos exceptions
            // but only if also matches known list or professional keywords to avoid false positives
            if ($this->mentionsKnownClient($this->normalize($client))) {
                return true;
            }
        }
        foreach (config('historical_scope_rules.professional_context_patterns', []) as $pat) {
            if (@preg_match($pat, $norm) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isPeajeEstacionamiento(string $norm): bool
    {
        foreach (config('historical_scope_rules.peaje_estacionamiento_patterns', []) as $pat) {
            if (@preg_match($pat, $norm) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isEnvioEquipo(string $norm): bool
    {
        foreach (config('historical_scope_rules.envio_equipo_patterns', []) as $pat) {
            if (@preg_match($pat, $norm) === 1) {
                return true;
            }
        }

        return false;
    }
}
