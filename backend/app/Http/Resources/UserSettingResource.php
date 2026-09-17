<?php

namespace App\Http\Resources;

use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Paramètres utilisateur (brief §14). `partage_profil_foyer` est lu depuis
 * household_members.share_profile et vaut null sans foyer.
 *
 * @property UserSetting $resource
 */
class UserSettingResource extends JsonResource
{
    public function __construct(UserSetting $settings, private readonly ?bool $partageProfilFoyer)
    {
        parent::__construct($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $heure = $this->resource->heure_rappel;

        return [
            'notif_peremption' => (bool) $this->resource->notif_peremption,
            'notif_rappel_repas' => (bool) $this->resource->notif_rappel_repas,
            'notif_rappel_sport' => (bool) $this->resource->notif_rappel_sport,
            'heure_rappel' => is_string($heure) && $heure !== '' ? substr($heure, 0, 5) : null,
            'jours_alerte_peremption' => (int) $this->resource->jours_alerte_peremption,
            'unites' => (string) $this->resource->unites,
            'theme' => (string) $this->resource->theme,
            'langue' => (string) $this->resource->langue,
            'timezone' => (string) $this->resource->timezone,
            'ia_seances' => (bool) $this->resource->ia_seances,
            'partage_profil_foyer' => $this->partageProfilFoyer,
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
