<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Household\CommonMealPreviewRequest;
use App\Http\Requests\Household\CommonMealStoreRequest;
use App\Http\Requests\Household\JoinHouseholdRequest;
use App\Http\Requests\Household\StoreHouseholdRequest;
use App\Http\Requests\Household\TransferOwnershipRequest;
use App\Http\Requests\Household\UpdateHouseholdRequest;
use App\Http\Requests\Household\UpdateMemberRequest;
use App\Http\Resources\HouseholdResource;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Household\CommonMealService;
use App\Services\Household\HouseholdService;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Foyer / Famille (brief §10, module M6). Les actions « propriétaire » passent par
 * HouseholdPolicy (403 « Action non autorisée. »). Les identifiants étrangers donnent 404.
 */
class HouseholdController extends Controller
{
    public function __construct(
        private readonly HouseholdService $households,
        private readonly CommonMealService $commonMeals,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------------------------

    /**
     * GET /household → {data: null} ou {data: HouseholdResource}.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->households->householdOf($user);

        if ($household === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->payload($household, $user)]);
    }

    /**
     * GET /household/preview?invite_code= → {data:{name, members_count}} / 404 « Code introuvable. ».
     */
    public function preview(JoinHouseholdRequest $request): JsonResponse
    {
        $household = $this->households->findByInviteCode($request->inviteCode());

        if ($household === null) {
            return $this->codeIntrouvable();
        }

        return response()->json([
            'data' => [
                'name' => (string) $household->name,
                'members_count' => $household->members()->count(),
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------
    // Création / adhésion
    // ------------------------------------------------------------------------------------

    /**
     * POST /household {name} → 201 {message, data, merged_stock_items}.
     */
    public function store(StoreHouseholdRequest $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->households->create($user, (string) $request->validated('name'));

        return response()->json([
            'message' => 'Foyer créé.',
            'data' => $this->payload($result['household'], $user),
            'merged_stock_items' => $result['merged_stock_items'],
        ], 201);
    }

    /**
     * POST /household/join {invite_code} → {message, data, merged_stock_items}.
     */
    public function join(JoinHouseholdRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($this->households->findByInviteCode($request->inviteCode()) === null) {
            return $this->codeIntrouvable();
        }

        $result = $this->households->join($user, $request->inviteCode());

        return response()->json([
            'message' => 'Bienvenue dans le foyer « '.$result['household']->name.' ».',
            'data' => $this->payload($result['household'], $user),
            'merged_stock_items' => $result['merged_stock_items'],
        ]);
    }

    // ------------------------------------------------------------------------------------
    // Actions propriétaire
    // ------------------------------------------------------------------------------------

    /**
     * PUT /household {name} (propriétaire).
     */
    public function update(UpdateHouseholdRequest $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $this->authorize('update', $household);

        $this->households->rename($household, (string) $request->validated('name'));

        return response()->json([
            'message' => 'Foyer renommé.',
            'data' => $this->payload($household, $user),
        ]);
    }

    /**
     * POST /household/regenerate-code (propriétaire).
     */
    public function regenerateCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $this->authorize('regenerateCode', $household);

        $this->households->regenerateCode($household);

        return response()->json([
            'message' => 'Nouveau code d’invitation généré.',
            'data' => $this->payload($household, $user),
        ]);
    }

    /**
     * POST /household/transfer {user_id} (propriétaire) — additif au brief.
     */
    public function transfer(TransferOwnershipRequest $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $this->authorize('transfer', $household);

        $target = User::query()
            ->whereIn('id', $household->members()->select('user_id'))
            ->findOrFail((int) $request->validated('user_id'));

        $this->households->transferOwnership($household, $target);

        return response()->json([
            'message' => 'Propriété du foyer transférée à '.$target->name.'.',
            'data' => $this->payload($household->refresh(), $user),
        ]);
    }

    /**
     * DELETE /household/members/{user} (propriétaire).
     */
    public function removeMember(Request $request, int $user): JsonResponse
    {
        $viewer = $request->user();
        $household = $this->requireHousehold($viewer);
        $this->authorize('removeMember', $household);

        $target = User::query()
            ->whereIn('id', $household->members()->select('user_id'))
            ->findOrFail($user);

        $this->households->removeMember($household, $target);

        return response()->json([
            'message' => $target->name.' a été retiré du foyer.',
            'data' => $this->payload($household, $viewer),
        ]);
    }

    /**
     * DELETE /household (propriétaire) : dissolution, tout revient au propriétaire.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $this->authorize('delete', $household);

        $this->households->dissolve($household);

        return response()->json(['message' => 'Foyer supprimé.']);
    }

    // ------------------------------------------------------------------------------------
    // Actions membre
    // ------------------------------------------------------------------------------------

    /**
     * POST /household/leave.
     */
    public function leave(Request $request): JsonResponse
    {
        $result = $this->households->leave($request->user());

        return response()->json([
            'message' => $result['dissolved']
                ? 'Tu étais seul dans le foyer : il a été supprimé.'
                : 'Tu as quitté le foyer.',
        ]);
    }

    /**
     * PUT /household/members/me {share_profile}.
     */
    public function updateMe(UpdateMemberRequest $request): JsonResponse
    {
        $user = $request->user();
        $membership = $this->requireMembership($user);

        $this->households->updateShareProfile($membership, (bool) $request->validated('share_profile'));

        return response()->json([
            'message' => $membership->share_profile
                ? 'Ton profil est partagé avec le foyer.'
                : 'Ton profil n’est plus partagé avec le foyer.',
            'data' => $this->payload($membership->getRelation('household'), $user),
        ]);
    }

    // ------------------------------------------------------------------------------------
    // Repas commun
    // ------------------------------------------------------------------------------------

    /**
     * POST /household/common-meal/preview {recipe_id, meal_type, date?}.
     */
    public function commonMealPreview(CommonMealPreviewRequest $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $recipe = $this->visibleRecipe($user, (int) $request->validated('recipe_id'));
        $date = Clock::date($user, $request->validated('date'));

        return response()->json([
            'data' => $this->commonMeals->preview($user, $household, $recipe, (string) $request->validated('meal_type'), $date),
        ]);
    }

    /**
     * POST /household/common-meal {recipe_id, meal_type, date, portions:{user_id: facteur}} → 201.
     */
    public function commonMealStore(CommonMealStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $household = $this->requireHousehold($user);
        $recipe = $this->visibleRecipe($user, (int) $request->validated('recipe_id'));

        $created = $this->commonMeals->create(
            $user,
            $household,
            $recipe,
            (string) $request->validated('meal_type'),
            (string) $request->validated('date'),
            (array) $request->validated('portions'),
        );

        return response()->json([
            'message' => count($created) > 1
                ? 'Repas commun enregistré pour '.count($created).' membres.'
                : 'Repas commun enregistré.',
            'data' => ['created' => $created],
        ], 201);
    }

    // ------------------------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------------------------

    private function payload(Household $household, User $viewer): array
    {
        $household->load(['members.user.profile']);

        return (new HouseholdResource($household, $viewer))->toArray(request());
    }

    private function requireMembership(User $user): HouseholdMember
    {
        $membership = $this->households->membershipOf($user);

        if ($membership === null) {
            throw ValidationException::withMessages(['household' => [HouseholdService::MSG_AUCUN_FOYER]]);
        }

        return $membership;
    }

    private function requireHousehold(User $user): Household
    {
        return $this->requireMembership($user)->getRelation('household');
    }

    /**
     * Recette visible par l'utilisateur (publique ou la sienne), sinon 404.
     */
    private function visibleRecipe(User $user, int $recipeId): Recipe
    {
        return Recipe::query()
            ->where(function ($q) use ($user) {
                $q->where('is_public', true)->orWhere('created_by_user_id', $user->id);
            })
            ->findOrFail($recipeId);
    }

    private function codeIntrouvable(): JsonResponse
    {
        return response()->json(['message' => HouseholdService::MSG_CODE_INTROUVABLE], 404);
    }
}
