<?php

namespace App\Services\Application;

use Illuminate\Support\Facades\Storage;

/**
 * La dernière version publiée de l'application Android, distribuée hors du Play Store.
 *
 * Le fichier et sa fiche vivent dans storage/app/android (volume persistant) : `version.json`
 * décrit la version courante, l'APK porte son numéro dans son nom. La fiche n'est réécrite
 * qu'après la copie complète de l'APK, si bien qu'une application qui la lit ne trouve jamais
 * l'annonce d'un fichier encore incomplet.
 */
class VersionAndroid
{
    public const DOSSIER = 'android';

    private const FICHE = self::DOSSIER.'/version.json';

    /**
     * @return array{version_code: int, version_name: string, notes: string|null, taille: int, sha256: string, fichier: string, publiee_le: string}|null
     */
    public function courante(): ?array
    {
        $disque = Storage::disk('local');

        if (! $disque->exists(self::FICHE)) {
            return null;
        }

        $fiche = json_decode((string) $disque->get(self::FICHE), true);

        if (! is_array($fiche) || ! isset($fiche['fichier']) || ! $disque->exists(self::DOSSIER.'/'.$fiche['fichier'])) {
            return null;
        }

        return $fiche;
    }

    public function cheminApk(array $fiche): string
    {
        return Storage::disk('local')->path(self::DOSSIER.'/'.$fiche['fichier']);
    }

    /**
     * Publie un APK : copie, empreinte, puis fiche. Les anciennes versions sont retirées.
     *
     * @return array<string, mixed> La fiche publiée
     */
    public function publier(string $source, int $code, string $nom, ?string $notes): array
    {
        $disque = Storage::disk('local');
        $fichier = sprintf('mavioh-%d.apk', $code);

        $disque->makeDirectory(self::DOSSIER);
        copy($source, $disque->path(self::DOSSIER.'/'.$fichier));

        $chemin = $disque->path(self::DOSSIER.'/'.$fichier);
        $fiche = [
            'version_code' => $code,
            'version_name' => $nom,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'taille' => filesize($chemin),
            'sha256' => hash_file('sha256', $chemin),
            'fichier' => $fichier,
            'publiee_le' => now()->toIso8601String(),
        ];

        $disque->put(self::FICHE, json_encode($fiche, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Le disque local crée des dossiers privés (0700) : PHP-FPM, qui sert l'APK, doit pouvoir
        // les lire même quand la publication a été lancée sous un autre compte.
        @chmod($disque->path(self::DOSSIER), 0755);
        @chmod($chemin, 0644);
        @chmod($disque->path(self::FICHE), 0644);

        foreach ($disque->files(self::DOSSIER) as $ancien) {
            if (str_ends_with($ancien, '.apk') && basename($ancien) !== $fichier) {
                $disque->delete($ancien);
            }
        }

        return $fiche;
    }
}
