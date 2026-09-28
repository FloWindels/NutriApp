<?php

namespace App\Services\Recipes;

use App\Exceptions\LlmUnavailableException;

/**
 * Schéma imposé au modèle et normalisation de sa réponse.
 *
 * Le modèle propose un titre, des ingrédients et des étapes ; il ne décide de rien d'autre. Les
 * valeurs nutritionnelles qu'il avancerait sont ignorées : le serveur les recalcule depuis sa
 * base d'aliments.
 */
class RecipeProposalSchema
{
    public const UNITES = ['g', 'ml', 'piece', 'cas', 'cac', 'tranche', 'portion', 'poignee'];

    public const MAX_INGREDIENTS = 20;

    public const MAX_ETAPES = 15;

    /** @return array<string, mixed> */
    public static function json(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['titre', 'description', 'portions', 'temps_preparation_min', 'ingredients', 'etapes', 'remarque'],
            'properties' => [
                'titre' => ['type' => 'string', 'description' => 'Nom de la recette, en français'],
                'description' => ['type' => 'string', 'description' => 'Une ou deux phrases'],
                'portions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                'temps_preparation_min' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 240],
                'ingredients' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_INGREDIENTS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['nom', 'quantite', 'unite', 'du_stock'],
                        'properties' => [
                            'nom' => ['type' => 'string'],
                            'quantite' => ['type' => 'number', 'minimum' => 0],
                            'unite' => ['type' => 'string', 'enum' => self::UNITES],
                            'du_stock' => ['type' => 'boolean', 'description' => 'Vrai si l’aliment figure dans le stock fourni'],
                        ],
                    ],
                ],
                'etapes' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_ETAPES,
                    'items' => ['type' => 'string'],
                ],
                'remarque' => ['type' => 'string', 'description' => 'Une phrase, vide si rien à signaler'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brut
     * @return array{titre: string, description: string, portions: int, temps_preparation_min: int, ingredients: list<array<string, mixed>>, etapes: list<string>, remarque: string}
     *
     * @throws LlmUnavailableException
     */
    public function normalize(array $brut): array
    {
        $titre = trim((string) ($brut['titre'] ?? ''));

        if (mb_strlen($titre) < 3) {
            throw new LlmUnavailableException('Réponse sans titre de recette exploitable.');
        }

        $ingredients = [];

        foreach ((array) ($brut['ingredients'] ?? []) as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $nom = trim((string) ($ligne['nom'] ?? ''));

            if (mb_strlen($nom) < 2) {
                continue;
            }

            $quantite = (float) ($ligne['quantite'] ?? 0);
            $unite = (string) ($ligne['unite'] ?? 'g');

            $ingredients[] = [
                'nom' => mb_substr($nom, 0, 120),
                'quantite' => $quantite > 0 && $quantite <= 5000 ? round($quantite, 1) : 100.0,
                'unite' => in_array($unite, self::UNITES, true) ? $unite : 'g',
                'du_stock' => (bool) ($ligne['du_stock'] ?? false),
            ];

            if (count($ingredients) >= self::MAX_INGREDIENTS) {
                break;
            }
        }

        if ($ingredients === []) {
            throw new LlmUnavailableException('Réponse sans ingrédient exploitable.');
        }

        $etapes = [];

        foreach ((array) ($brut['etapes'] ?? []) as $etape) {
            $texte = trim((string) $etape);

            if ($texte !== '') {
                $etapes[] = mb_substr($texte, 0, 400);
            }

            if (count($etapes) >= self::MAX_ETAPES) {
                break;
            }
        }

        return [
            'titre' => mb_substr($titre, 0, 255),
            'description' => mb_substr(trim((string) ($brut['description'] ?? '')), 0, 800),
            'portions' => max(1, min(12, (int) ($brut['portions'] ?? 2))),
            'temps_preparation_min' => max(1, min(240, (int) ($brut['temps_preparation_min'] ?? 20))),
            'ingredients' => $ingredients,
            'etapes' => $etapes,
            'remarque' => mb_substr(trim((string) ($brut['remarque'] ?? '')), 0, 300),
        ];
    }
}
