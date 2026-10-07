<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\Equipment;
use App\Http\Requests\Sport\StoreLieuRequest;
use App\Http\Requests\Sport\UpdateLieuRequest;
use App\Http\Resources\Sport\SportLieuResource;
use App\Models\SportLieu;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lieux d'entraînement (Sport v2 §1) : chaque lieu porte son matériel, la séance se génère
 * pour un lieu. Gratuit comme tout le suivi sportif. Chargés à travers l'utilisateur : le lieu
 * d'un autre compte donne 404 « Introuvable. ».
 *
 * Invariant : dès qu'un compte a des lieux, exactement un est « par défaut » — le premier créé
 * le devient d'office, en désigner un autre retire le drapeau au précédent, et supprimer le
 * lieu par défaut le transmet au plus ancien des restants.
 */
class SportLieuController extends SportController
{
    public const MSG_PLAFOND = 'Tu as atteint le nombre maximal de lieux ('.SportLieu::MAX_PAR_COMPTE.').';

    /**
     * GET /sport/lieux → {data: [Lieu]} (par défaut en premier, puis par nom).
     */
    public function index(Request $request): JsonResponse
    {
        $lieux = $this->lieux($request->user())->ordonnes()->get();

        return $this->json(['data' => SportLieuResource::collection($lieux)->resolve($request)]);
    }

    /**
     * POST /sport/lieux {nom, type, materiel?, par_defaut?} → 201 {message, data}.
     * Sans `materiel`, le préréglage du type s'applique.
     */
    public function store(StoreLieuRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if ($this->lieux($user)->count() >= SportLieu::MAX_PAR_COMPTE) {
            throw ValidationException::withMessages(['lieu' => [self::MSG_PLAFOND]]);
        }

        $lieu = DB::transaction(function () use ($user, $validated) {
            $premier = ! $this->lieux($user)->exists();
            $parDefaut = $premier || (bool) ($validated['par_defaut'] ?? false);

            if ($parDefaut) {
                $this->lieux($user)->update(['par_defaut' => false]);
            }

            return SportLieu::query()->create([
                'user_id' => $user->id,
                'nom' => trim((string) $validated['nom']),
                'type' => $validated['type'],
                'materiel' => SportLieu::normaliserMateriel($validated['materiel'] ?? Equipment::preset($validated['type'])),
                'par_defaut' => $parDefaut,
            ]);
        });

        return $this->json([
            'message' => 'Lieu ajouté.',
            'data' => SportLieuResource::make($lieu)->resolve($request),
        ], 201);
    }

    /**
     * PUT /sport/lieux/{lieu} → {message, data}.
     */
    public function update(UpdateLieuRequest $request, int $lieu): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var SportLieu $model */
        $model = $this->lieux($user)->findOrFail($lieu);

        DB::transaction(function () use ($user, $model, $validated) {
            $changes = [];
            if (array_key_exists('nom', $validated)) {
                $changes['nom'] = trim((string) $validated['nom']);
            }
            if (array_key_exists('type', $validated)) {
                $changes['type'] = $validated['type'];
            }
            if (array_key_exists('materiel', $validated)) {
                $changes['materiel'] = SportLieu::normaliserMateriel((array) $validated['materiel']);
            }

            if (($validated['par_defaut'] ?? null) === true && ! $model->par_defaut) {
                $this->lieux($user)->whereKeyNot($model->id)->update(['par_defaut' => false]);
                $changes['par_defaut'] = true;
            } elseif (($validated['par_defaut'] ?? null) === false && $model->par_defaut) {
                // Retirer le drapeau le transmet au plus ancien des autres ; seul lieu : il le garde.
                $suivant = $this->lieux($user)->whereKeyNot($model->id)->orderBy('id')->first();
                if ($suivant !== null) {
                    $suivant->forceFill(['par_defaut' => true])->save();
                    $changes['par_defaut'] = false;
                }
            }

            $model->fill($changes)->save();
        });

        return $this->json([
            'message' => 'Lieu mis à jour.',
            'data' => SportLieuResource::make($model->refresh())->resolve($request),
        ]);
    }

    /**
     * DELETE /sport/lieux/{lieu} → {message}. Séances et calendrier gardent leur type de lieu
     * et leur matériel figé ; seul le lien est retiré.
     */
    public function destroy(Request $request, int $lieu): JsonResponse
    {
        $user = $request->user();

        /** @var SportLieu $model */
        $model = $this->lieux($user)->findOrFail($lieu);

        DB::transaction(function () use ($user, $model) {
            // La clé étrangère s'en charge sur PostgreSQL ; SQLite n'ajoute pas de contrainte
            // à une table existante, d'où ce détachement explicite.
            WorkoutSession::query()->where('user_id', $user->id)->where('lieu_id', $model->id)->update(['lieu_id' => null]);
            SportPlan::query()->where('user_id', $user->id)->where('lieu_id', $model->id)->update(['lieu_id' => null]);

            $etaitDefaut = $model->par_defaut;
            $model->delete();

            if ($etaitDefaut) {
                $this->lieux($user)->orderBy('id')->first()?->forceFill(['par_defaut' => true])->save();
            }
        });

        return $this->json(['message' => 'Lieu supprimé.']);
    }

    /**
     * @return Builder<SportLieu>
     */
    private function lieux(User $user): Builder
    {
        return SportLieu::query()->where('user_id', $user->id);
    }
}
