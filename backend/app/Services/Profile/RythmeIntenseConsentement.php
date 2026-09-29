<?php

namespace App\Services\Profile;

use App\Models\ConsentementRythme;
use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use Carbon\CarbonImmutable;

/**
 * Jusqu'où porte un accord donné pour perdre plus vite que le rythme conseillé, et ce qu'on en
 * conserve.
 *
 * Un accord ne vaut que pour le déficit qui a été présenté ce jour-là. Dès que le calcul en
 * propose un plus grand — délai raccourci, poids repris — il faut le redemander : reconduire en
 * silence reviendrait à faire accepter un rythme que personne n'a vu.
 */
final class RythmeIntenseConsentement
{
    /** Tolérance d'arrondi entre le déficit calculé et celui consenti, en kcal/j. */
    private const MARGE = 0.5;

    /**
     * Un accord neuf ne vaut que si le client déclare le chiffre et le texte qu'il a montrés.
     *
     * Se fier à la seule case cochée ne prouve rien : le formulaire web la renvoie à chaque
     * sauvegarde, y compris quand le poids ou le délai ont changé depuis l'affichage. Le déficit
     * appliqué dépasserait alors celui que la personne a vu, et la trace attesterait un accord
     * pour un rythme que personne ne lui a présenté.
     *
     * @param  array<string, mixed>  $data     Corps de la requête
     * @param  array<string, mixed>  $besoins  Besoins recalculés par le serveur
     */
    public static function accordPresente(array $data, array $besoins): bool
    {
        $vu = $data['rythme_intense_deficit_vu'] ?? null;

        if ($vu === null || ! is_numeric($vu)) {
            return false;
        }

        // Le texte des risques doit être celui en vigueur : s'il a ete reecrit depuis, la personne
        // doit le relire avant que son accord reprenne effet.
        $version = $data['rythme_intense_version_vue'] ?? null;

        if ($version !== NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION) {
            return false;
        }

        return abs((float) $besoins['ajustement_kcal']) <= (float) $vu + self::MARGE;
    }

    /**
     * L'accord peut-il s'appliquer a ce calcul ?
     *
     * Deux chemins, un seul critere : le deficit calcule ne depasse jamais celui qui a ete
     * presente. Soit le client vient de le presenter (accord neuf), soit il reconduit un accord
     * deja enregistre — l'application mobile, qui n'affiche pas encore la case, est dans ce cas.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $besoins
     */
    public static function accepte(array $data, ?Profile $profile, array $besoins): bool
    {
        return self::accordPresente($data, $besoins) || self::couvre($profile, $besoins);
    }

    /**
     * @param  array<string, mixed>  $besoins
     */
    public static function couvre(?Profile $profile, array $besoins): bool
    {
        if (! $profile?->rythme_intense) {
            return false;
        }

        $consenti = (float) ($profile->rythme_intense_deficit_kcal ?? 0);

        return abs((float) $besoins['ajustement_kcal']) <= $consenti + self::MARGE;
    }

    /**
     * Colonnes d'ETAT a ecrire sur le profil : un accord s'applique-t-il en ce moment, et pour
     * quel deficit. Sans consentement actif, tout repasse a null — retirer son accord doit
     * vraiment ramener au rythme conseille, sans residu.
     *
     * La preuve, elle, ne vit pas ici : elle est consignee en ajout seul dans
     * `consentements_rythme`, que rien n'efface. Voir journaliser().
     *
     * @param  array<string, mixed>  $besoins
     * @return array<string, mixed>
     */
    public static function trace(?Profile $profile, array $besoins): array
    {
        if (! $besoins['rythme_intense_accepte']) {
            return [
                'rythme_intense' => false,
                'rythme_intense_consenti_le' => null,
                'rythme_intense_avertissement_version' => null,
                'rythme_intense_deficit_kcal' => null,
                'rythme_intense_kg_semaine' => null,
            ];
        }

        $version = NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION;

        // La date reste celle du premier accord tant qu'il couvre le rythme en cours et que le
        // texte n'a pas changé : la repousser à chaque sauvegarde effacerait le seul jour où les
        // risques ont réellement été présentés.
        $memeAccord = self::couvre($profile, $besoins)
            && $profile->rythme_intense_consenti_le !== null
            && $profile->rythme_intense_avertissement_version === $version;

        return [
            'rythme_intense' => true,
            'rythme_intense_consenti_le' => $memeAccord ? $profile->rythme_intense_consenti_le : CarbonImmutable::now(),
            'rythme_intense_avertissement_version' => $version,
            'rythme_intense_deficit_kcal' => (int) round(abs((float) $besoins['ajustement_kcal'])),
            'rythme_intense_kg_semaine' => abs((float) $besoins['variation_hebdo_kg']),
        ];
    }

    /**
     * Consigne l'accord, ou sa fin, dans l'historique en ajout seul.
     *
     * Une ligne nouvelle n'est ecrite que lorsque l'accord change vraiment : premier accord, ou
     * rythme plus rapide que celui deja consenti, ou texte des risques reecrit. Enregistrer son
     * profil tous les jours sans rien changer ne doit pas noyer la trace sous les doublons.
     *
     * @param  array<string, mixed>  $besoins
     */
    public static function journaliser(User $user, array $besoins): void
    {
        $actif = ConsentementRythme::query()
            ->where('user_id', $user->id)
            ->whereNull('retire_le')
            ->latest('donne_le')
            ->first();

        if (! $besoins['rythme_intense_accepte']) {
            $actif?->forceFill(['retire_le' => CarbonImmutable::now()])->save();

            return;
        }

        $deficit = (int) round(abs((float) $besoins['ajustement_kcal']));
        $version = NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION;

        if ($actif !== null && $actif->version_texte === $version && $deficit <= $actif->deficit_kcal) {
            return;
        }

        $actif?->forceFill(['retire_le' => CarbonImmutable::now()])->save();

        ConsentementRythme::query()->create([
            'user_id' => $user->id,
            'donne_le' => CarbonImmutable::now(),
            'version_texte' => $version,
            'deficit_kcal' => $deficit,
            'kg_semaine' => round(abs((float) $besoins['variation_hebdo_kg']), 2),
        ]);
    }
}
