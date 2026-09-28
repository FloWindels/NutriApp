<?php

namespace App\Services\Search;

use App\Contracts\WebSearchClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recherche via une instance SearXNG.
 *
 * Ce moteur a été retenu parce qu'il s'auto-héberge : le propriétaire peut le faire tourner sur
 * sa propre machine, sans clé d'API et sans compte, ce qui préserve la promesse « rien ne sort
 * de la machine » autant que possible pour une fonction qui, par nature, interroge le web.
 *
 * Trois protections sont appliquées ici, avant même que le modèle ne voie quoi que ce soit :
 * seul le texte est retenu — jamais de HTML, jamais de script —, chaque extrait est tronqué, et
 * une liste blanche de domaines peut restreindre les sources. Une recherche qui échoue n'est
 * jamais bloquante : elle rend une liste vide et la génération se poursuit sans sources.
 */
class SearxWebSearchClient implements WebSearchClient
{
    /** Longueur maximale d'un extrait : au-delà, on donne surtout de la place à l'injection. */
    private const MAX_EXTRAIT = 400;

    private const CACHE_MINUTES = 60;

    public function search(string $requete, int $max = 4): array
    {
        $base = rtrim((string) config('services.recherche.base_url'), '/');
        $requete = $this->nettoyer($requete);

        if ($base === '' || $requete === '') {
            return [];
        }

        $cle = 'recherche:'.md5($base.'|'.$requete.'|'.$max);

        return Cache::remember($cle, now()->addMinutes(self::CACHE_MINUTES), function () use ($base, $requete, $max) {
            try {
                $reponse = Http::timeout((int) config('services.recherche.timeout', 8))
                    ->acceptJson()
                    ->get($base.'/search', [
                        'q' => $requete,
                        'format' => 'json',
                        'language' => 'fr',
                        'safesearch' => 1,
                    ]);
            } catch (Throwable $e) {
                Log::info('Recherche web indisponible.', ['exception' => get_class($e)]);

                return [];
            }

            if ($reponse->failed()) {
                return [];
            }

            return $this->extraire((array) $reponse->json('results', []), $max);
        });
    }

    /**
     * @param  array<int, mixed>  $resultats
     * @return list<array{titre: string, extrait: string, url: string, domaine: string}>
     */
    private function extraire(array $resultats, int $max): array
    {
        $autorises = array_filter(array_map('trim', explode(',', (string) config('services.recherche.domaines', ''))));
        $sources = [];

        foreach ($resultats as $resultat) {
            if (! is_array($resultat)) {
                continue;
            }

            $url = (string) ($resultat['url'] ?? '');
            $domaine = strtolower((string) parse_url($url, PHP_URL_HOST));

            if ($domaine === '' || ! str_starts_with($url, 'https://')) {
                continue;
            }

            if ($autorises !== [] && ! $this->autorise($domaine, $autorises)) {
                continue;
            }

            $sources[] = [
                'titre' => $this->texte((string) ($resultat['title'] ?? ''), 160),
                'extrait' => $this->texte((string) ($resultat['content'] ?? ''), self::MAX_EXTRAIT),
                'url' => $url,
                'domaine' => $domaine,
            ];

            if (count($sources) >= $max) {
                break;
            }
        }

        return $sources;
    }

    /** @param list<string> $autorises */
    private function autorise(string $domaine, array $autorises): bool
    {
        foreach ($autorises as $permis) {
            $permis = strtolower(ltrim($permis, '.'));

            if ($domaine === $permis || str_ends_with($domaine, '.'.$permis)) {
                return true;
            }
        }

        return false;
    }

    /** Texte brut uniquement : balises retirées, espaces normalisés, longueur bornée. */
    private function texte(string $valeur, int $max): string
    {
        $propre = html_entity_decode(strip_tags($valeur), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $propre = preg_replace('/\s+/u', ' ', $propre) ?? '';

        return mb_substr(trim($propre), 0, $max);
    }

    /**
     * La requête part telle quelle sur Internet : elle ne doit donc contenir que ce que la
     * personne a tapé, et rien de son profil. On borne aussi sa longueur.
     */
    private function nettoyer(string $requete): string
    {
        $propre = preg_replace('/[\r\n]+/', ' ', $requete) ?? '';

        return mb_substr(trim($propre), 0, 160);
    }
}
