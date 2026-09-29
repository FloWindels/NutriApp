<?php

namespace App\Services\Magasins;

use App\Models\Magasin;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Collection;

/**
 * Promotions en cours d'un magasin, et ce qu'elles pèsent sur les propositions de repas.
 *
 * Une promotion est bornée dans le temps, et c'est tout l'intérêt : ce qui est en promotion cette
 * semaine ne l'est plus la semaine prochaine. La fenêtre est donc évaluée au jour de la personne
 * (Clock::today, qui tient compte de son fuseau), jamais au jour du serveur.
 *
 * Les libellés servent au planificateur : ils font remonter, À QUALITÉ ÉGALE, une recette dont un
 * ingrédient est en promotion. Jamais devant une recette personnelle, jamais devant un stock qui
 * périme — le gaspillage coûte plus cher qu'une remise.
 */
class PromotionsActives
{
    /**
     * Mémoire de la requête en cours : le planificateur redemande les mêmes libellés pour chaque
     * créneau de la semaine, soit des dizaines de fois. Sans ce cache, une génération de semaine
     * ajouterait une centaine de requêtes pour lire exactement la même chose.
     *
     * @var array<string, list<string>>
     */
    private array $memo = [];

    public function __construct(private readonly ChoixMagasin $choix)
    {
    }

    /**
     * @return Collection<int, Promotion>
     */
    public function pour(Magasin $magasin, string $jour): Collection
    {
        return Promotion::query()
            ->where('magasin_id', $magasin->id)
            ->actives($jour)
            ->with('produit:id,libelle,rayon,prix_indicatif,unite,quantite_reference')
            ->orderBy('fin')
            ->orderBy('id')
            ->get();
    }

    /**
     * Libellés normalisés en promotion aujourd'hui pour le magasin préféré de la personne.
     *
     * Aucun magasin préféré, aucune promotion en cours : liste vide, et le planificateur se
     * comporte exactement comme avant. C'est ce qui permet de brancher cette entrée sur le tri
     * existant sans rien lui imposer.
     *
     * @return list<string>
     */
    public function libellesPour(User $user): array
    {
        $jour = Clock::today($user);
        $cle = $user->id.'|'.$jour;

        if (isset($this->memo[$cle])) {
            return $this->memo[$cle];
        }

        $magasin = $this->choix->pour($user);

        if ($magasin === null) {
            return $this->memo[$cle] = [];
        }

        $libelles = [];

        foreach ($this->pour($magasin, $jour) as $promotion) {
            foreach ([$promotion->libelle_normalise, $promotion->produit?->libelle_normalise] as $libelle) {
                $libelle = trim((string) $libelle);
                if ($libelle !== '') {
                    $libelles[$libelle] = true;
                }
            }
        }

        return $this->memo[$cle] = array_keys($libelles);
    }

    /**
     * Combien d'ingrédients de cette recette sont en promotion ?
     *
     * Un décompte, et non un booléen : une recette qui profite de trois promotions vaut mieux
     * qu'une qui n'en profite que d'une.
     *
     * @param  list<string>  $ingredients  libellés bruts
     * @param  list<string>  $promotions  libellés déjà normalisés
     */
    public static function compte(array $ingredients, array $promotions): int
    {
        if ($promotions === []) {
            return 0;
        }

        $compte = 0;

        foreach ($ingredients as $ingredient) {
            $cle = LibelleProduit::normaliser((string) $ingredient);

            foreach ($promotions as $promotion) {
                if (LibelleProduit::correspond($cle, $promotion)) {
                    $compte++;

                    break;
                }
            }
        }

        return $compte;
    }
}
