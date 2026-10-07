<?php

namespace App\Services\Foods;

use App\Contracts\LlmNutritionClient;
use App\Contracts\WebSearchClient;
use App\Exceptions\LlmBusyException;
use App\Exceptions\LlmUnavailableException;
use App\Models\Food;
use App\Services\Search\PageWebSure;
use Illuminate\Support\Facades\Log;

/**
 * Valeurs nutritionnelles d'un aliment absent du catalogue, trouvées sur Internet.
 *
 * 1. Le catalogue, au nom exact : inutile de chercher ce qu'on a déjà.
 * 2. Une recherche web (SearXNG, auto-hébergé) — seul le nom de l'aliment part, jamais rien de
 *    la personne — puis la lecture des deux premières pages, bornée et sans accès au réseau
 *    interne (PageWebSure).
 * 3. Le modèle relève les valeurs pour 100 g dans ces extraits, ou les estime d'après une recette
 *    type s'il s'agit d'un plat. Le serveur vérifie qu'elles se tiennent (énergie ≈ macros).
 *
 * Rien n'est enregistré : la proposition pré-remplit le formulaire « Créer un aliment », que la
 * personne vérifie avant d'enregistrer.
 */
class RechercheNutritionEnLigne
{
    private const PAGES_LUES = 2;

    public function __construct(
        private readonly WebSearchClient $recherche,
        private readonly PageWebSure $pages,
        private readonly LlmNutritionClient $modele,
    ) {
    }

    /**
     * @return array{source: string, food: Food|null, proposition: array<string, mixed>|null, sources: list<array{titre: string, url: string}>, avertissements: list<string>, message: string|null}
     */
    public function chercher(string $nom, ?string $marque): array
    {
        $nom = trim($nom);
        $marque = $marque !== null && trim($marque) !== '' ? trim($marque) : null;

        $existant = Food::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nom)])
            ->when($marque !== null, fn ($q) => $q->whereRaw('LOWER(COALESCE(brand, \'\')) = ?', [mb_strtolower($marque)]))
            ->orderByDesc('is_verified')
            ->first();

        if ($existant !== null) {
            return $this->reponse('catalogue', food: $existant, message: 'Cet aliment est déjà dans le catalogue.');
        }

        $resultats = $this->recherche->search(
            trim($nom.' '.($marque ?? '').' valeurs nutritionnelles pour 100 g kcal protéines glucides lipides'),
            5,
        );

        $sources = [];
        foreach ($resultats as $i => $resultat) {
            $texte = $resultat['extrait'];
            if ($i < self::PAGES_LUES) {
                $page = $this->pages->extraitNutrition($resultat['url']);
                if ($page !== null) {
                    $texte = trim($texte.' … '.$page);
                }
            }
            $sources[] = ['n' => $i + 1, 'titre' => $resultat['titre'], 'url' => $resultat['url'], 'texte' => $texte];
        }

        try {
            $brut = $this->modele->extraireValeurs(
                ['aliment' => $nom, 'marque' => $marque, 'sources' => $sources],
                ValeursNutritionnellesSchema::json(),
            );
        } catch (LlmBusyException $e) {
            throw $e;
        } catch (LlmUnavailableException $e) {
            Log::info('Valeurs nutritionnelles en ligne indisponibles.', ['raison' => $e->getMessage()]);

            return $this->reponse('indisponible', message: 'La recherche des valeurs n’a pas abouti. Saisis-les depuis l’emballage.');
        }

        $propre = ValeursNutritionnellesSchema::normaliser($brut, $nom, $marque, $sources);

        if ($propre === null) {
            return $this->reponse('indisponible', message: 'Aucune valeur fiable trouvée pour cet aliment. Saisis-les depuis l’emballage.');
        }

        return $this->reponse(
            'internet',
            proposition: $propre['proposition'],
            sources: $propre['sources'],
            avertissements: $propre['avertissements'],
            message: $resultats === []
                ? 'Aucune page trouvée : valeurs estimées d’après une recette type.'
                : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $proposition
     * @param  list<array{titre: string, url: string}>  $sources
     * @param  list<string>  $avertissements
     */
    private function reponse(string $source, ?Food $food = null, ?array $proposition = null, array $sources = [], array $avertissements = [], ?string $message = null): array
    {
        return compact('source', 'food', 'proposition', 'sources', 'avertissements', 'message');
    }
}
