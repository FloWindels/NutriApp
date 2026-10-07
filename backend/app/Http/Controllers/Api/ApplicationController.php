<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Application\VersionAndroid;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Mise à jour de l'application Android : la version disponible, et le fichier lui-même.
 * Réservé aux comptes connectés, comme tout le reste de l'API.
 */
class ApplicationController extends Controller
{
    public function android(VersionAndroid $versions): JsonResponse
    {
        $fiche = $versions->courante();

        if ($fiche === null) {
            return response()->json(['message' => 'Aucune version publiée.'], 404);
        }

        return response()->json(['data' => [
            'version_code' => $fiche['version_code'],
            'version_name' => $fiche['version_name'],
            'notes' => $fiche['notes'],
            'taille' => $fiche['taille'],
            'sha256' => $fiche['sha256'],
            'publiee_le' => $fiche['publiee_le'],
        ]]);
    }

    public function apk(VersionAndroid $versions): BinaryFileResponse|JsonResponse
    {
        $fiche = $versions->courante();

        if ($fiche === null) {
            return response()->json(['message' => 'Aucune version publiée.'], 404);
        }

        return response()->download(
            $versions->cheminApk($fiche),
            sprintf('Mavioh-%s.apk', $fiche['version_name']),
            ['Content-Type' => 'application/vnd.android.package-archive'],
        );
    }
}
