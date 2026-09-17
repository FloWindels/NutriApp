<?php

namespace App\Http\Resources;

use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recommendation
 */
class RecommendationResource extends JsonResource
{
    /**
     * @return array{id: int, date: string|null, type: string, title: string, message: string, factors: array, actions: array, priority: int, status: string, is_estimate: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'date' => $this->date?->format('Y-m-d'),
            'type' => (string) $this->type,
            'title' => (string) $this->title,
            'message' => (string) $this->message,
            'factors' => array_values((array) ($this->factors ?? [])),
            'actions' => array_values((array) ($this->actions ?? [])),
            'priority' => (int) $this->priority,
            'status' => (string) $this->status,
            'is_estimate' => (bool) $this->is_estimate,
        ];
    }
}
