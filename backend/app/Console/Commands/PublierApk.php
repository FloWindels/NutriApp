<?php

namespace App\Console\Commands;

use App\Services\Application\VersionAndroid;
use Illuminate\Console\Command;

/**
 * Publie une nouvelle version de l'application Android : à sa prochaine ouverture, chaque
 * application installée la propose en mise à jour.
 *
 *   php artisan mavioh:publier-apk /tmp/app-release.apk --code=3 --nom=1.2.0 --notes="Nouveautés…"
 *
 * Le script scripts/publier-apk.sh compile, signe et publie en une commande.
 */
class PublierApk extends Command
{
    protected $signature = 'mavioh:publier-apk
        {fichier : Chemin de l’APK signé}
        {--code= : versionCode Android (entier croissant, la partie après « + » dans pubspec.yaml)}
        {--nom= : versionName affiché (ex. 1.2.0)}
        {--notes= : Ce qui change, montré à l’utilisateur}';

    protected $description = 'Publie une version de l’application Android pour la mise à jour intégrée';

    public function handle(VersionAndroid $versions): int
    {
        $fichier = (string) $this->argument('fichier');
        $code = filter_var($this->option('code'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nom = trim((string) $this->option('nom'));

        if (! is_file($fichier) || ! str_ends_with(strtolower($fichier), '.apk')) {
            $this->error("APK introuvable : {$fichier}");

            return self::FAILURE;
        }
        if ($code === false || $nom === '') {
            $this->error('--code (entier ≥ 1) et --nom sont obligatoires.');

            return self::FAILURE;
        }

        $actuelle = $versions->courante();
        if ($actuelle !== null && $code <= $actuelle['version_code']) {
            $this->error("Le code {$code} n’est pas supérieur à la version publiée ({$actuelle['version_code']}) : les applications ne verraient pas de mise à jour.");

            return self::FAILURE;
        }

        $fiche = $versions->publier($fichier, $code, $nom, $this->option('notes'));

        $this->info(sprintf('Version %s (code %d) publiée — %s Mo, sha256 %s.', $fiche['version_name'], $fiche['version_code'], round($fiche['taille'] / 1048576, 1), substr($fiche['sha256'], 0, 12).'…'));

        return self::SUCCESS;
    }
}
