<?php

namespace App\Http\Resources;

use App\Models\WeightLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WeightLog
 */
class WeightLogResource extends JsonResource
{
    /**
     * @return array{date: string, weight_kg: float}
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date?->format('Y-m-d'),
            'weight_kg' => (float) $this->weight_kg,
        ];
    }
}
