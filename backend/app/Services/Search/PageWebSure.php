<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lit le texte utile d'une page web trouvée par la recherche, sans ouvrir de porte vers le
 * réseau interne (SSRF) : HTTPS seulement, adresse résolue puis vérifiée publique et épinglée
 * pour la requête (pas de rebond DNS), aucune redirection suivie, taille et durée bornées.
 *
 * Ne garde que les passages qui parlent de valeurs nutritionnelles : c'est tout ce qui sert, et
 * moins il entre de texte venu d'Internet dans un prompt, moins il y a de place pour l'injection.
 */
class PageWebSure
{
    private const TAILLE_MAX = 1_500_000;

    private const MOTS = ['kcal', 'kj', 'énergie', 'energie', 'protéines', 'proteines', 'glucides', 'lipides', 'matières grasses', 'fibres', 'sucres', 'sel'];

    /** Résolution DNS, remplaçable par les tests. @var callable(string): list<string> */
    public $resoudre;

    public function __construct()
    {
        $this->resoudre = static function (string $hote): array {
            $ips = [];
            foreach ([DNS_A, DNS_AAAA] as $type) {
                foreach (@dns_get_record($hote, $type) ?: [] as $enregistrement) {
                    $ips[] = $enregistrement['ip'] ?? $enregistrement['ipv6'] ?? null;
                }
            }

            return array_values(array_filter($ips));
        };
    }

    /** Passages utiles de la page (1 500 caractères au plus), ou null si elle est refusée ou illisible. */
    public function extraitNutrition(string $url): ?string
    {
        $parties = parse_url($url);
        $hote = strtolower((string) ($parties['host'] ?? ''));

        if (($parties['scheme'] ?? '') !== 'https' || $hote === '' || isset($parties['user']) || isset($parties['port'])) {
            return null;
        }

        $ips = ($this->resoudre)($hote);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::ipPublique($ip)) {
                return null;
            }
        }

        try {
            $reponse = Http::timeout(6)
                ->withOptions([
                    'allow_redirects' => false,
                    // Épingle l'adresse vérifiée : un second appel DNS ne peut plus la changer.
                    'curl' => [CURLOPT_RESOLVE => [sprintf('%s:443:%s', $hote, str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0])]],
                ])
                ->withHeaders(['User-Agent' => (string) config('services.off.user_agent', 'Mavioh/1.0'), 'Accept' => 'text/html'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($reponse->status() !== 200 || ! str_contains(strtolower($reponse->header('Content-Type')), 'text/html')) {
            return null;
        }

        return self::passages(substr($reponse->body(), 0, self::TAILLE_MAX));
    }

    public static function ipPublique(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Texte brut de la page, puis fenêtres autour des mots de la nutrition. */
    public static function passages(string $html): ?string
    {
        $html = (string) preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/li|/td|/th|/h\d)\b[^>]*>#i', ' $0', $html);
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        if ($texte === '') {
            return null;
        }

        $minuscule = mb_strtolower($texte);
        $fenetres = [];
        foreach (self::MOTS as $mot) {
            $position = mb_strpos($minuscule, $mot);
            if ($position !== false) {
                $fenetres[] = [max(0, $position - 150), $position + 350];
            }
        }
        if ($fenetres === []) {
            return null;
        }

        // Fusionne les fenêtres qui se chevauchent, dans l'ordre de la page.
        usort($fenetres, fn ($a, $b) => $a[0] <=> $b[0]);
        $fusion = [];
        foreach ($fenetres as [$debut, $fin]) {
            if ($fusion !== [] && $debut <= $fusion[count($fusion) - 1][1]) {
                $fusion[count($fusion) - 1][1] = max($fusion[count($fusion) - 1][1], $fin);
            } else {
                $fusion[] = [$debut, $fin];
            }
        }

        $extrait = implode(' … ', array_map(fn ($f) => mb_substr($texte, $f[0], $f[1] - $f[0]), $fusion));

        return mb_substr($extrait, 0, 1500);
    }
}
