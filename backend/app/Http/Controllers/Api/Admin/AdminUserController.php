<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des comptes, sous une règle qui tient en une phrase :
 * **l'administrateur voit l'enveloppe, jamais le contenu.**
 *
 * Ce qu'il peut : lister, chercher, suspendre, lever une suspension. Ce qu'il ne peut pas, et
 * qui n'est pas qu'une consigne — aucun modèle de contenu n'est même importé ici : lire un
 * journal alimentaire, un profil de santé, des pesées ou des séances ; déclencher un export à
 * la place de quelqu'un ; se connecter « en tant que » ; modifier un profil ou des objectifs.
 *
 * Un test vérifie que ce fichier ne référence aucun de ces modèles.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AdminJournal $journal) {}

    public function index(Request $request): JsonResponse
    {
        $recherche = trim((string) $request->query('q', ''));

        $query = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'role', 'household_id',
                'suspendu_le', 'suspension_motif', 'cgu_version', 'confidentialite_version',
                'consentement_sante_at', 'created_at'])
            ->orderByDesc('created_at');

        if ($recherche !== '') {
            $motif = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($recherche)).'%';
            $query->where(function ($q) use ($motif) {
                $q->whereRaw('LOWER(name) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$motif]);
            });
        }

        $page = $query->paginate(perPage: 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verifie' => $user->email_verified_at !== null,
                'role' => $user->role->value,
                'dans_un_foyer' => $user->household_id !== null,
                'suspendu_le' => $user->suspendu_le?->toISOString(),
                'suspension_motif' => $user->suspension_motif,
                'cgu_version' => $user->cgu_version,
                'confidentialite_version' => $user->confidentialite_version,
                'consentement_sante' => $user->consentement_sante_at !== null,
                'created_at' => $user->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function suspend(Request $request, int $user): JsonResponse
    {
        $motif = $this->motif($request);
        $cible = User::query()->findOrFail($user);
        $admin = $request->user();

        if ($cible->is($admin)) {
            throw ValidationException::withMessages([
                'motif' => ['Tu ne peux pas suspendre ton propre compte.'],
            ]);
        }

        if ($cible->isAdmin()) {
            throw ValidationException::withMessages([
                'motif' => ['Un administrateur ne peut pas en suspendre un autre depuis l’interface.'],
            ]);
        }

        $cible->forceFill(['suspendu_le' => now(), 'suspension_motif' => $motif])->save();
        // La suspension doit produire son effet tout de suite, pas à l'expiration du jeton.
        $cible->tokens()->delete();

        $this->journal->enregistrer($admin, 'suspendre_compte', 'user', $cible->id, $cible->email, $motif);

        return response()->json(['message' => 'Compte suspendu.']);
    }

    public function restore(Request $request, int $user): JsonResponse
    {
        $motif = $this->motif($request);
        $cible = User::query()->findOrFail($user);

        $cible->forceFill(['suspendu_le' => null, 'suspension_motif' => null])->save();

        $this->journal->enregistrer($request->user(), 'lever_suspension', 'user', $cible->id, $cible->email, $motif);

        return response()->json(['message' => 'Suspension levée.']);
    }

    /** Un motif écrit est exigé : une action sans raison n'est pas défendable. */
    private function motif(Request $request): string
    {
        $valide = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:255']]);

        return $valide['motif'];
    }
}
