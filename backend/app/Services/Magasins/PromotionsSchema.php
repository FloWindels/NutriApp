<?php

namespace App\Services\Magasins;

/**
 * Schéma imposé au modèle qui relève les promotions, et nettoyage de sa réponse.
 *
 * Le nettoyage est la seule chose à laquelle on fasse confiance. Tout ce que le modèle rend
 * repasse ici : un libellé vide, un prix négatif, une remise « de 90 % », une source qui n'est
 * pas une des pages réellement consultées — tout cela est écarté sans discussion. Ce qui survit
 * est enregistré NON VÉRIFIÉ : le filtre réduit le bruit, il ne fabrique pas une certitude.
 */
class PromotionsSchema
{
    public const MAX_PROMOTIONS = 30;

    /** Au-delà, ce n'est plus une promotion de supermarché mais une erreur de lecture. */
    public const PRIX_MAX = 500.0;

    /** @return array<string, mixed> */
    public static function json(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['promotions'],
            'properties' => [
                'promotions' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_PROMOTIONS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['libelle', 'prix_promotionnel', 'prix_avant', 'debut', 'fin', 'source'],
                        'properties' => [
                            'libelle' => ['type' => 'string', 'description' => 'Produit tel qu’écrit dans l’extrait'],
                            'prix_promotionnel' => ['type' => ['number', 'null'], 'minimum' => 0],
                            'prix_avant' => ['type' => ['number', 'null'], 'minimum' => 0],
                            'debut' => ['type' => 'string', 'description' => 'AAAA-MM-JJ, vide si l’extrait ne la donne pas'],
                            'fin' => ['type' => 'string', 'description' => 'AAAA-MM-JJ, vide si l’extrait ne la donne pas'],
                            'source' => ['type' => 'string', 'description' => 'URL de l’extrait dont vient cette promotion'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brut
     * @param  list<string>  $urlsAutorisees  adresses réellement consultées par la recherche
     * @param  array{debut: string, fin: string}  $semaine  fenêtre par défaut
     * @return list<array{libelle: string, libelle_normalise: string, prix_promotionnel: float|null, prix_avant: float|null, debut: string, fin: string, source: string}>
     */
    public function normalize(array $brut, array $urlsAutorisees, array $semaine): array
    {
        $lignes = is_array($brut['promotions'] ?? null) ? $brut['promotions'] : [];
        $propres = [];
        $vues = [];

        foreach ($lignes as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $libelle = mb_substr(trim(strip_tags((string) ($ligne['libelle'] ?? ''))), 0, 160);
            $normalise = LibelleProduit::normaliser($libelle);

            if (mb_strlen($libelle) < 2 || $normalise === '' || isset($vues[$normalise])) {
                continue;
            }

            // La source doit être une des pages réellement ramenées par la recherche. Sans cette
            // vérification, le modèle pourrait citer une adresse qu'il a inventée, et la
            // promotion deviendrait invérifiable — donc inutilisable.
            $source = trim((string) ($ligne['source'] ?? ''));
            if (! in_array($source, $urlsAutorisees, true)) {
                continue;
            }

            $promo = $this->prix($ligne['prix_promotionnel'] ?? null);
            $avant = $this->prix($ligne['prix_avant'] ?? null);

            // Un « avant » inférieur au « pendant » n'est pas une remise : c'est une lecture ratée.
            if ($promo !== null && $avant !== null && $avant <= $promo) {
                $avant = null;
            }

            $debut = $this->date($ligne['debut'] ?? null) ?? $semaine['debut'];
            $fin = $this->date($ligne['fin'] ?? null) ?? $semaine['fin'];

            if ($fin < $debut) {
                $fin = $semaine['fin'];
                $debut = $semaine['debut'];
            }

            $vues[$normalise] = true;
            $propres[] = [
                'libelle' => $libelle,
                'libelle_normalise' => $normalise,
                'prix_promotionnel' => $promo,
                'prix_avant' => $avant,
                'debut' => $debut,
                'fin' => $fin,
                'source' => $source,
            ];

            if (count($propres) >= self::MAX_PROMOTIONS) {
                break;
            }
        }

        return $propres;
    }

    private function prix(mixed $valeur): ?float
    {
        if ($valeur === null || ! is_numeric($valeur)) {
            return null;
        }

        $prix = round((float) $valeur, 2);

        return ($prix > 0 && $prix <= self::PRIX_MAX) ? $prix : null;
    }

    private function date(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1 ? $valeur : null;
    }
}
