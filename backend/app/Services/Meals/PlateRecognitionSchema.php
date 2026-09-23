<?php

namespace App\Services\Meals;

use App\Exceptions\LlmUnavailableException;

/**
 * Schéma imposé à la réponse du modèle de vision, et normalisation de ce qu'il renvoie.
 *
 * Le modèle propose ; le serveur borne. Les unités sont limitées à celles qu'un item de repas
 * accepte réellement, sinon la ligne serait rejetée au moment de l'enregistrement.
 */
class PlateRecognitionSchema
{
    /** Unités acceptées par un item « personnalisé » (ValidatesMealItems). */
    public const UNITES = ['g', 'ml', 'piece', 'portion'];

    public const MAX_LIGNES = 12;

    public const MAX_QUANTITE = 5000.0;

    /** @return array<string, mixed> */
    public static function json(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['aliments', 'description', 'confiance_globale', 'avertissements'],
            'properties' => [
                'aliments' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_LIGNES,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['nom', 'marque', 'quantite', 'unite', 'confiance', 'calories', 'proteines', 'glucides', 'lipides'],
                        'properties' => [
                            'nom' => ['type' => 'string', 'description' => 'Nom courant en français, sans marque'],
                            'marque' => ['type' => ['string', 'null']],
                            'quantite' => ['type' => 'number', 'description' => 'Quantité estimée pour la portion visible'],
                            'unite' => ['type' => 'string', 'enum' => self::UNITES],
                            'confiance' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'calories' => ['type' => ['number', 'null'], 'description' => 'Pour la portion visible, kcal'],
                            'proteines' => ['type' => ['number', 'null']],
                            'glucides' => ['type' => ['number', 'null']],
                            'lipides' => ['type' => ['number', 'null']],
                        ],
                    ],
                ],
                'description' => ['type' => 'string', 'description' => 'Une phrase décrivant l’assiette'],
                'confiance_globale' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'avertissements' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 4],
            ],
        ];
    }

    /**
     * Nettoie et borne la réponse brute.
     *
     * @param  array<string, mixed>  $raw
     * @return array{aliments: list<array<string, mixed>>, description: string, confiance_globale: float, avertissements: list<string>}
     *
     * @throws LlmUnavailableException quand rien d'exploitable n'en ressort
     */
    public function normalize(array $raw): array
    {
        $lignes = [];

        foreach ((array) ($raw['aliments'] ?? []) as $brut) {
            if (! is_array($brut)) {
                continue;
            }

            $nom = trim((string) ($brut['nom'] ?? ''));

            if (mb_strlen($nom) < 2) {
                continue;
            }

            $quantite = (float) ($brut['quantite'] ?? 0);

            if ($quantite <= 0 || $quantite > self::MAX_QUANTITE) {
                // Une quantité aberrante ne disqualifie pas la ligne : l'utilisateur la corrigera.
                $quantite = 100.0;
            }

            $unite = (string) ($brut['unite'] ?? 'g');

            $lignes[] = [
                'nom' => mb_substr($nom, 0, 120),
                'marque' => self::texteOuNull($brut['marque'] ?? null, 80),
                'quantite' => round($quantite, 1),
                'unite' => in_array($unite, self::UNITES, true) ? $unite : 'g',
                'confiance' => self::borne((float) ($brut['confiance'] ?? 0.5), 0, 1),
                'calories' => self::nombreOuNull($brut['calories'] ?? null),
                'proteines' => self::nombreOuNull($brut['proteines'] ?? null),
                'glucides' => self::nombreOuNull($brut['glucides'] ?? null),
                'lipides' => self::nombreOuNull($brut['lipides'] ?? null),
            ];

            if (count($lignes) >= self::MAX_LIGNES) {
                break;
            }
        }

        if ($lignes === []) {
            throw new LlmUnavailableException('Aucun aliment identifiable sur cette photo.');
        }

        $avertissements = [];

        foreach ((array) ($raw['avertissements'] ?? []) as $message) {
            $texte = self::texteOuNull($message, 200);

            if ($texte !== null) {
                $avertissements[] = $texte;
            }
        }

        return [
            'aliments' => $lignes,
            'description' => (string) (self::texteOuNull($raw['description'] ?? null, 200) ?? ''),
            'confiance_globale' => self::borne((float) ($raw['confiance_globale'] ?? 0.5), 0, 1),
            'avertissements' => array_values(array_slice(array_unique($avertissements), 0, 4)),
        ];
    }

    private static function texteOuNull(mixed $valeur, int $max): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }

        $texte = trim($valeur);

        return $texte === '' ? null : mb_substr($texte, 0, $max);
    }

    private static function nombreOuNull(mixed $valeur): ?float
    {
        if (! is_numeric($valeur)) {
            return null;
        }

        $nombre = (float) $valeur;

        return $nombre < 0 || $nombre > 100000 ? null : round($nombre, 1);
    }

    private static function borne(float $valeur, float $min, float $max): float
    {
        return round(max($min, min($max, $valeur)), 2);
    }
}
