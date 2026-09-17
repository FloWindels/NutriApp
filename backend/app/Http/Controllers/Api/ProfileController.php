<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\PreviewProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfilePayload;
use App\Services\NutritionCalculator;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil nutrition & sport (brief §2). GET/PUT renvoient la charge utile historique au premier
 * niveau (jamais dans `data`) ; PUT y ajoute `message`.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly NutritionCalculator $nutrition,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile()->first();

        return response()->json(ProfilePayload::for($user, $profile, $this->nutrition)->toArray());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $profile = $this->profiles->update($user, $request->validated());
        $user->refresh();

        return response()->json(
            ['message' => 'Profil mis à jour.'] + ProfilePayload::for($user, $profile, $this->nutrition)->toArray()
        );
    }

    public function preview(PreviewProfileRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profiles->preview($request->user(), $request->validated()),
        ]);
    }
}
