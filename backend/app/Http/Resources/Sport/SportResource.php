<?php

namespace App\Http\Resources\Sport;

use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sport du catalogue (addendum §C.1) : publics + sports personnalisés de l'utilisateur.
 *
 * @mixin Sport
 */
class SportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Sport $sport */
        $sport = $this->resource;
        $user = $request->user();

        return [
            'id' => (int) $sport->id,
            'name' => (string) $sport->name,
            'slug' => (string) $sport->slug,
            'category' => (string) $sport->category,
            'met_faible' => (float) $sport->met_faible,
            'met_moderee' => (float) $sport->met_moderee,
            'met_elevee' => (float) $sport->met_elevee,
            'icon' => $sport->icon,
            'is_public' => (bool) $sport->is_public,
            'is_mine' => $user !== null && $sport->isMineFor($user),
        ];
    }
}
