<?php

namespace App\Console\Commands;

use App\Enums\Enseigne;
use App\Enums\Rayon;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Services\Magasins\LibelleProduit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Charge l'assortiment et les prix d'une enseigne depuis un fichier CSV ou JSON.
 *
 * Cette commande existe pour une raison simple : les prix bougent, et le propriétaire ne doit
 * pas avoir à ouvrir un fichier PHP pour corriger un prix de riz. Il édite un tableur, il
 * l'exporte, il lance la commande.
 *
 *   php artisan mavioh:magasin-importer lidl chemin/vers/lidl.csv
 *   php artisan mavioh:magasin-importer colruyt colruyt.json --date=2026-10-05
 *   php artisan mavioh:magasin-importer aldi aldi.csv --simulation
 *
 * FORMAT CSV — première ligne = en-têtes, séparateur « ; » ou « , », encodage UTF-8 :
 *
 *   libelle;rayon;prix;unite;quantite;marque;code_barres
 *   Riz basmati 1 kg;feculents;1,79;kg;1;Lidl;20123456
 *
 * FORMAT JSON — un tableau d'objets portant les mêmes clés :
 *
 *   [{"libelle":"Riz basmati 1 kg","rayon":"feculents","prix":1.79,"unite":"kg","quantite":1}]
 *
 * Seul `libelle` est obligatoire. `rayon` doit être une des clés connues (la commande les liste
 * en cas d'erreur), sinon la ligne part en « autre » plutôt que d'être rejetée : un rayon approximatif
 * vaut mieux qu'un produit perdu. Un prix illisible laisse la ligne SANS prix — jamais zéro, jamais
 * un prix deviné.
 *
 * L'import est idempotent : deux passages sur le même fichier donnent le même résultat, la clé
 * étant (magasin, libellé normalisé).
 */
class ImporterProduitsMagasin extends Command
{
    protected $signature = 'mavioh:magasin-importer
        {enseigne : lidl, colruyt, delhaize ou aldi}
        {fichier : Chemin d’un fichier .csv ou .json}
        {--date= : Date du relevé des prix (AAAA-MM-JJ) ; aujourd’hui par défaut}
        {--magasin= : Nom du magasin quand l’enseigne en a plusieurs}
        {--simulation : Analyse le fichier et rend compte, sans rien écrire}';

    protected $description = 'Importe l’assortiment et les prix indicatifs d’une enseigne depuis un CSV ou un JSON';

    public function handle(): int
    {
        $enseigne = Enseigne::tryFrom(mb_strtolower(trim((string) $this->argument('enseigne'))));

        if ($enseigne === null) {
            $this->error('Enseigne inconnue. Valeurs acceptées : '.implode(', ', Enseigne::values()).'.');

            return self::FAILURE;
        }

        $fichier = (string) $this->argument('fichier');

        if (! is_file($fichier) || ! is_readable($fichier)) {
            $this->error("Fichier introuvable ou illisible : {$fichier}");

            return self::FAILURE;
        }

        $date = $this->dateReleve();

        if ($date === null) {
            $this->error('La date du relevé doit s’écrire AAAA-MM-JJ.');

            return self::FAILURE;
        }

        try {
            $lignes = str_ends_with(mb_strtolower($fichier), '.json')
                ? $this->lireJson($fichier)
                : $this->lireCsv($fichier);
        } catch (Throwable $e) {
            $this->error('Lecture impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        if ($lignes === []) {
            $this->warn('Aucune ligne exploitable dans ce fichier.');

            return self::FAILURE;
        }

        $nom = trim((string) $this->option('magasin')) ?: $enseigne->label();

        $magasin = Magasin::query()->updateOrCreate(
            ['enseigne' => $enseigne->value, 'nom' => $nom],
            ['pays' => 'BE', 'actif' => true],
        );

        [$prets, $ignorees, $sansPrix, $rayonsInconnus, $fusionnees] = $this->preparer($lignes, $magasin->id, $date);

        if ($prets === []) {
            $this->error('Aucun produit exploitable : vérifie les en-têtes du fichier.');
            $this->line('Attendues : libelle, marque, rayon, prix, unite, quantite, code_barres.');
            $this->line('Lues : '.implode(', ', array_keys($lignes[0] ?? [])));

            return self::FAILURE;
        }

        if ($this->option('simulation')) {
            $this->rapport($prets, $ignorees, $sansPrix, $rayonsInconnus, $fusionnees, true);

            return self::SUCCESS;
        }

        // Deux paquets, parce que toutes les colonnes ne se mettent pas à jour dans les deux cas :
        // un fichier sans colonne de prix décrit un assortiment, il ne dit pas que les prix
        // relevés hier sont devenus inconnus. Les écraser à null, avec leur date, ferait perdre en
        // silence un travail de relevé.
        $colonnes = ['libelle', 'marque', 'rayon', 'code_barres', 'unite', 'quantite_reference', 'updated_at'];

        DB::transaction(function () use ($prets, $colonnes) {
            $avecPrix = array_values(array_filter($prets, fn (array $p) => $p['prix_indicatif'] !== null));
            $sansPrix = array_values(array_filter($prets, fn (array $p) => $p['prix_indicatif'] === null));

            foreach (array_chunk($avecPrix, 50) as $paquet) {
                MagasinProduit::upsert($paquet, ['magasin_id', 'libelle_normalise'],
                    array_merge($colonnes, ['prix_indicatif', 'prix_maj_le']));
            }

            foreach (array_chunk($sansPrix, 50) as $paquet) {
                MagasinProduit::upsert($paquet, ['magasin_id', 'libelle_normalise'], $colonnes);
            }
        });

        $this->rapport($prets, $ignorees, $sansPrix, $rayonsInconnus, $fusionnees, false);
        $this->line('Magasin : '.$magasin->nom.' (#'.$magasin->id.').');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int, 3: list<string>}
     */
    private function preparer(array $lignes, int $magasinId, string $date): array
    {
        $maintenant = now();
        $prets = [];
        $fusionnees = 0;
        $ignorees = 0;
        $sansPrix = 0;
        $rayonsInconnus = [];

        foreach ($lignes as $ligne) {
            $libelle = mb_substr(trim((string) ($ligne['libelle'] ?? '')), 0, 255);
            $normalise = LibelleProduit::normaliser($libelle);

            // Un libellé qui ne laisse aucun mot utile ne pourra jamais être rattaché : l'importer
            // reviendrait à peupler l'assortiment de lignes muettes.
            if ($libelle === '' || $normalise === '') {
                $ignorees++;

                continue;
            }


            $rayonBrut = mb_strtolower(trim((string) ($ligne['rayon'] ?? '')));
            $rayon = Rayon::tryFrom($rayonBrut);

            if ($rayon === null && $rayonBrut !== '' && ! in_array($rayonBrut, $rayonsInconnus, true)) {
                $rayonsInconnus[] = $rayonBrut;
            }

            $prix = $this->nombre($ligne['prix'] ?? null);
            if ($prix === null) {
                $sansPrix++;
            }

            $quantite = $this->nombre($ligne['quantite'] ?? null);
            $codeBarres = preg_replace('/\D+/', '', (string) ($ligne['code_barres'] ?? '')) ?: null;

            // Doublon dans le fichier : la dernière ligne l'emporte, comme dans un tableur. On
            // indexe sur la clé réelle — un index numérique se décalait dès qu'une ligne était
            // retirée, et effaçait alors un produit sans rapport.
            $fusionnees += isset($prets[$normalise]) ? 1 : 0;
            $prets[$normalise] = [
                'magasin_id' => $magasinId,
                'libelle' => $libelle,
                'libelle_normalise' => $normalise,
                'marque' => ($marque = mb_substr(trim((string) ($ligne['marque'] ?? '')), 0, 255)) === '' ? null : $marque,
                'rayon' => ($rayon ?? Rayon::Autre)->value,
                'code_barres' => $codeBarres === null ? null : mb_substr($codeBarres, 0, 32),
                'prix_indicatif' => $prix,
                'unite' => mb_substr(trim((string) ($ligne['unite'] ?? 'piece')) ?: 'piece', 0, 16),
                'quantite_reference' => ($quantite !== null && $quantite > 0) ? $quantite : 1.0,
                // La date accompagne le prix, toujours. Une ligne sans prix ne prétend pas avoir
                // été relevée : sa date reste vide.
                'prix_maj_le' => $prix === null ? null : $date,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }

        return [array_values($prets), $ignorees, $sansPrix, $rayonsInconnus, $fusionnees];
    }

    /**
     * @param  list<array<string, mixed>>  $prets
     * @param  list<string>  $rayonsInconnus
     */
    private function rapport(array $prets, int $ignorees, int $sansPrix, array $rayonsInconnus, int $fusionnees, bool $simulation): void
    {
        $verbe = $simulation ? 'seraient importés' : 'importés';

        $this->info(count($prets).' produits '.$verbe.'.');

        if ($fusionnees > 0) {
            $this->warn($fusionnees.' ligne(s) en double dans le fichier : la dernière l’emporte. '
                .'Deux conditionnements d’un même produit portent le même libellé normalisé.');
        }

        if ($sansPrix > 0) {
            $this->warn($sansPrix.' sans prix lisible : ils restent au catalogue, sans prix indicatif.');
        }

        if ($ignorees > 0) {
            $this->warn($ignorees.' lignes ignorées faute de libellé exploitable.');
        }

        if ($rayonsInconnus !== []) {
            $this->warn('Rayons inconnus rangés en « autre » : '.implode(', ', $rayonsInconnus).'.');
            $this->line('Rayons acceptés : '.implode(', ', Rayon::values()).'.');
        }

        if ($simulation) {
            $this->line('Simulation : rien n’a été écrit en base.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lireCsv(string $fichier): array
    {
        $contenu = file_get_contents($fichier);

        if ($contenu === false) {
            throw new \RuntimeException('fichier vide ou illisible');
        }

        // Un tableur belge exporte volontiers en « ; » : on détecte plutôt que d'imposer.
        $premiere = strtok($contenu, "\r\n") ?: '';
        $separateur = substr_count($premiere, ';') >= substr_count($premiere, ',') ? ';' : ',';

        $handle = fopen($fichier, 'r');

        if ($handle === false) {
            throw new \RuntimeException('ouverture impossible');
        }

        $entetes = null;
        $lignes = [];

        while (($colonnes = fgetcsv($handle, 0, $separateur)) !== false) {
            if ($colonnes === [null] || $colonnes === false) {
                continue;
            }

            if ($entetes === null) {
                $entetes = array_map(
                    fn ($entete) => mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $entete))),
                    $colonnes,
                );

                continue;
            }

            $ligne = [];
            foreach ($entetes as $rang => $entete) {
                $ligne[$entete] = $colonnes[$rang] ?? null;
            }

            $lignes[] = $ligne;
        }

        fclose($handle);

        return $lignes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lireJson(string $fichier): array
    {
        $decode = json_decode((string) file_get_contents($fichier), true);

        if (! is_array($decode)) {
            throw new \RuntimeException('JSON illisible');
        }

        // On accepte aussi bien un tableau nu qu'un objet {"produits": [...]}.
        $lignes = array_is_list($decode) ? $decode : ($decode['produits'] ?? []);

        return array_values(array_filter(is_array($lignes) ? $lignes : [], 'is_array'));
    }

    private function dateReleve(): ?string
    {
        $date = trim((string) $this->option('date'));

        if ($date === '') {
            return CarbonImmutable::now()->toDateString();
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /** Accepte « 1,79 » comme « 1.79 » : un tableur francophone écrit la virgule. */
    private function nombre(mixed $valeur): ?float
    {
        if ($valeur === null) {
            return null;
        }

        $texte = str_replace([' ', ','], ['', '.'], trim((string) $valeur));

        if ($texte === '' || ! is_numeric($texte)) {
            return null;
        }

        $nombre = round((float) $texte, 3);

        return $nombre > 0 ? $nombre : null;
    }
}
