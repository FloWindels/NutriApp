<?php

namespace App\Services\Offres;

use App\Enums\Offre;
use App\Models\CodeAcces;
use App\Models\CodeAccesUtilisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création et consommation des codes d'accès.
 *
 * Un code est fait pour être dicté au téléphone ou recopié d'un message : l'alphabet exclut
 * donc les caractères qu'on confond à l'oral et à l'œil — ni O ni 0, ni I ni 1, ni S ni 5.
 */
class CodeAccesService
{
    /** Alphabet sans caractère ambigu. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRTUVWXYZ2346789';

    private const LONGUEUR = 8;

    public function creer(
        User $admin,
        Offre $offre,
        int $utilisationsMax = 1,
        ?int $dureeJours = null,
        ?string $expireLe = null,
        ?string $note = null,
    ): CodeAcces {
        return CodeAcces::query()->create([
            'code' => $this->codeUnique(),
            'offre' => $offre->value,
            'duree_jours' => $dureeJours,
            'utilisations_max' => max(1, min(1000, $utilisationsMax)),
            'expire_le' => $expireLe,
            'actif' => true,
            'note' => $note,
            'cree_par_id' => $admin->id,
        ]);
    }

    /**
     * Consomme un code au profit d'un compte.
     *
     * Tout se passe dans une transaction avec verrou : sans cela, deux requêtes simultanées
     * pourraient dépasser le plafond d'utilisations d'un code presque épuisé.
     *
     * @throws ValidationException
     */
    public function consommer(User $user, string $saisie): CodeAcces
    {
        $normalise = $this->normaliser($saisie);

        return DB::transaction(function () use ($user, $normalise) {
            $code = CodeAcces::query()->where('code', $normalise)->lockForUpdate()->first();

            if ($code === null || ! $code->estUtilisable()) {
                // Même message dans les deux cas : inutile d'indiquer qu'un code existe mais
                // qu'il est épuisé, cela aiderait seulement à deviner.
                throw ValidationException::withMessages([
                    'code' => ['Ce code n’est pas valide ou n’est plus utilisable.'],
                ]);
            }

            $dejaUtilise = CodeAccesUtilisation::query()
                ->where('code_acces_id', $code->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($dejaUtilise) {
                throw ValidationException::withMessages([
                    'code' => ['Tu as déjà utilisé ce code.'],
                ]);
            }

            // Un code ouvre, il ne referme jamais. Sans cette garde, quelqu'un à qui un
            // administrateur a posé l'offre Foyer à la main la perdrait en saisissant un code
            // « Complet, 30 jours » reçu ailleurs — et les membres de son foyer avec lui.
            $actuelle = $user->offreValide();

            if ($code->offre->rang() < $actuelle->rang()) {
                throw ValidationException::withMessages([
                    'code' => ['Ton offre actuelle est déjà plus complète que ce code.'],
                ]);
            }

            CodeAccesUtilisation::query()->create(['code_acces_id' => $code->id, 'user_id' => $user->id]);
            $code->increment('utilisations');

            $echeance = $code->duree_jours !== null ? now()->addDays($code->duree_jours) : null;

            // À rang égal, une échéance ne se rapproche pas : un code de 30 jours ne doit pas
            // raboter un accès accordé sans terme, ni une date plus lointaine déjà acquise.
            if ($code->offre === $actuelle && $echeance !== null
                && ($user->offre_expire_le === null || $user->offre_expire_le->greaterThan($echeance))) {
                $echeance = $user->offre_expire_le;
            }

            $user->forceFill([
                'offre' => $code->offre,
                // Sans durée, l'accès ne s'éteint pas de lui-même.
                'offre_expire_le' => $echeance,
            ])->save();

            return $code;
        });
    }

    /** Boucle jusqu'à obtenir un code libre ; la collision est très improbable mais possible. */
    private function codeUnique(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::LONGUEUR; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (CodeAcces::query()->where('code', $code)->exists());

        return $code;
    }

    /** Majuscules, sans espaces ni tirets : « abcd-1234 » et « ABCD1234 » sont le même code. */
    private function normaliser(string $saisie): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $saisie) ?? '');
    }
}
