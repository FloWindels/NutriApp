<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\UserSettingResource;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres (brief §14). Créés paresseusement (firstOrCreate) ; `partage_profil_foyer`
 * est lu / écrit dans household_members.share_profile.
 */
class SettingsController extends Controller
{
    public const MSG_ENREGISTRES = 'Paramètres enregistrés.';
    public const MSG_AUCUN_FOYER = 'Tu ne fais partie d’aucun foyer.';

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $settings = UserSetting::query()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'data' => new UserSettingResource($settings, $this->partageProfilFoyer($user)),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $settings = UserSetting::query()->firstOrCreate(['user_id' => $user->id]);
        $membership = HouseholdMember::query()->where('user_id', $user->id)->first();

        if (array_key_exists('partage_profil_foyer', $validated) && $membership === null) {
            throw ValidationException::withMessages([
                'partage_profil_foyer' => [self::MSG_AUCUN_FOYER],
            ]);
        }

        if (array_key_exists('heure_rappel', $validated) && is_string($validated['heure_rappel'])) {
            $validated['heure_rappel'] = strlen($validated['heure_rappel']) === 5
                ? $validated['heure_rappel'].':00'
                : $validated['heure_rappel'];
        }

        DB::transaction(function () use ($settings, $membership, $validated): void {
            $settings->fill(Arr::except($validated, ['partage_profil_foyer']))->save();

            if ($membership && array_key_exists('partage_profil_foyer', $validated)) {
                $membership->update(['share_profile' => (bool) $validated['partage_profil_foyer']]);
            }
        });

        // Le fuseau a pu changer : on invalide le cache par requête de Clock.
        Clock::forget($user);
        $user->setRelation('settings', $settings);

        $partage = $membership ? (bool) $membership->refresh()->share_profile : null;

        return response()->json([
            'message' => self::MSG_ENREGISTRES,
            'data' => new UserSettingResource($settings, $partage),
        ]);
    }

    private function partageProfilFoyer(User $user): ?bool
    {
        $share = HouseholdMember::query()->where('user_id', $user->id)->value('share_profile');

        return $share === null ? null : (bool) $share;
    }
}
