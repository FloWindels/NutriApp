<?php

namespace App\Services\Planner;

use App\Enums\MealType;
use App\Exceptions\LlmUnavailableException;

/**
 * Schéma imposé au modèle qui organise la semaine, et validation de sa réponse.
 *
 * Le modèle ne renvoie que des identifiants de recettes. Chacun est confronté à l'ensemble
 * autorisé pour ce créneau : un identifiant inventé, un identifiant appartenant à quelqu'un
 * d'autre ou une recette écartée par le régime tombe ici et n'est jamais planifié.
 */
class WeekProposalSchema
{
    /** 7 jours × 4 types de repas. */
    public const MAX_CRENEAUX = 28;

    public const MAX_EXPLICATION = 3;

    /** @return array<string, mixed> */
    public static function json(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['creneaux', 'explication'],
            'properties' => [
                'creneaux' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_CRENEAUX,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['date', 'repas', 'recette_id'],
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Date du créneau, au format AAAA-MM-JJ'],
                            'repas' => ['type' => 'string', 'enum' => MealType::values()],
                            'recette_id' => ['type' => 'integer', 'description' => 'Identifiant pris dans recettes_disponibles'],
                        ],
                    ],
                ],
                'explication' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_EXPLICATION,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brut  Réponse décodée du modèle
     * @param  array<string, list<int>>  $autorise  « {date}|{repas} » → identifiants permis sur ce créneau
     * @return array{choix: array<string, int>, explication: list<string>, rejets: int}
     *
     * @throws LlmUnavailableException quand rien d'exploitable n'en sort
     */
    public function normalize(array $brut, array $autorise): array
    {
        $choix = [];
        $rejets = 0;

        foreach ((array) ($brut['creneaux'] ?? []) as $ligne) {
            if (! is_array($ligne)) {
                $rejets++;

                continue;
            }

            $cle = trim((string) ($ligne['date'] ?? '')).'|'.trim((string) ($ligne['repas'] ?? ''));
            $id = (int) ($ligne['recette_id'] ?? 0);

            if ($id <= 0 || ! in_array($id, $autorise[$cle] ?? [], true)) {
                $rejets++;

                continue;
            }

            // Premier choix retenu : un modèle qui propose deux fois le même créneau ne doit pas
            // pouvoir écraser sa propre réponse valide par une seconde, fantaisiste.
            if (! array_key_exists($cle, $choix)) {
                $choix[$cle] = $id;
            }
        }

        if ($choix === []) {
            throw new LlmUnavailableException('Aucun créneau exploitable dans la réponse du modèle.');
        }

        $explication = [];

        foreach ((array) ($brut['explication'] ?? []) as $phrase) {
            $texte = trim((string) $phrase);

            if ($texte !== '') {
                $explication[] = mb_substr($texte, 0, 300);
            }

            if (count($explication) >= self::MAX_EXPLICATION) {
                break;
            }
        }

        return ['choix' => $choix, 'explication' => $explication, 'rejets' => $rejets];
    }
}
