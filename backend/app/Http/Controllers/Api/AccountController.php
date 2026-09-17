<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\User;
use App\Services\Account\AccountDeletionService;
use App\Services\Account\AccountExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Compte (brief §1) : identité, mot de passe, suppression, export.
 */
class AccountController extends Controller
{
    public const MSG_COMPTE_MIS_A_JOUR = 'Compte mis à jour.';
    public const MSG_MOT_DE_PASSE_MODIFIE = 'Mot de passe modifié. Tes autres sessions ont été déconnectées.';
    public const MSG_MOT_DE_PASSE_ACTUEL = 'Le mot de passe actuel est incorrect.';
    public const MSG_COMPTE_SUPPRIME = 'Compte supprimé. À bientôt peut-être !';

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => self::MSG_COMPTE_MIS_A_JOUR,
            'data' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
        ]);
    }

    /**
     * Change le mot de passe et révoque tous les autres jetons (la session courante reste valide).
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [self::MSG_MOT_DE_PASSE_ACTUEL],
            ]);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        $current = $user->currentAccessToken();
        $others = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $others->where('id', '!=', $current->id);
        }

        $others->delete();

        return response()->json(['message' => self::MSG_MOT_DE_PASSE_MODIFIE]);
    }

    public function destroy(DeleteAccountRequest $request, AccountDeletionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $service->delete($user, (string) $request->validated('password'));

        return response()->json(['message' => self::MSG_COMPTE_SUPPRIME]);
    }

    public function export(Request $request, AccountExporter $exporter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $exporter->export($user)]);
    }
}
