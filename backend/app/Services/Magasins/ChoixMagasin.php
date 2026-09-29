<?php

namespace App\Services\Magasins;

use App\Models\Magasin;
use App\Models\User;
use App\Models\UserSetting;

/**
 * Quel magasin regarde-t-on ?
 *
 * Trois sources, dans cet ordre : celui demandé par la requête, sinon le magasin préféré de la
 * personne, sinon aucun. « Aucun » est un cas normal et non une erreur : la liste de courses
 * existe très bien sans magasin, elle n'affiche alors ni rayon ni prix.
 *
 * Un magasin inactif n'est jamais rendu : il a été retiré du service, ses prix ne valent plus rien.
 */
final class ChoixMagasin
{
    public function pour(User $user, ?int $demande = null): ?Magasin
    {
        if ($demande !== null) {
            return Magasin::query()->where('actif', true)->find($demande);
        }

        $prefere = UserSetting::query()->where('user_id', $user->id)->value('magasin_prefere_id');

        return $prefere === null
            ? null
            : Magasin::query()->where('actif', true)->find((int) $prefere);
    }
}
