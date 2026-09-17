<?php

namespace App\Http\Requests\Sport;

/**
 * POST /sport/calendar/{plan}/propose — même corps que la génération, mais les défauts
 * viennent du plan (sport, durée, lieu, heure) : `duration_min` devient optionnel.
 */
class ProposePlanSessionRequest extends GenerateSessionRequest
{
    /**
     * @return array<int, string>
     */
    protected function durationRule(): array
    {
        return ['nullable'];
    }
}
