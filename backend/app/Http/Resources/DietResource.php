<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche complète d'un régime (config/diets.php). La ressource est un tableau
 * `['key' => …] + config('diets.{key}')`.
 */
class DietResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $diet = (array) $this->resource;

        return [
            'key' => (string) ($diet['key'] ?? ''),
            'nom' => (string) ($diet['nom'] ?? ''),
            'description' => (string) ($diet['description'] ?? ''),
            'principes' => array_values((array) ($diet['principes'] ?? [])),
            'regles' => (object) ((array) ($diet['regles'] ?? [])),
            'aliments_conseilles' => array_values((array) ($diet['aliments_conseilles'] ?? [])),
            'aliments_a_limiter' => array_values((array) ($diet['aliments_a_limiter'] ?? [])),
            'conseils' => array_values((array) ($diet['conseils'] ?? [])),
            'mineurs_autorise' => (bool) ($diet['mineurs_autorise'] ?? true),
        ];
    }

    /**
     * Version courte pour la liste du catalogue.
     *
     * @return array{key: string, nom: string, description: string, principes: list<string>, mineurs_autorise: bool}
     */
    public static function summary(string $key, array $diet): array
    {
        return [
            'key' => $key,
            'nom' => (string) ($diet['nom'] ?? $key),
            'description' => (string) ($diet['description'] ?? ''),
            'principes' => array_values((array) ($diet['principes'] ?? [])),
            'mineurs_autorise' => (bool) ($diet['mineurs_autorise'] ?? true),
        ];
    }
}
