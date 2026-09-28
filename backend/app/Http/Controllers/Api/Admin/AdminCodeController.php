<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Offre;
use App\Http\Controllers\Controller;
use App\Models\CodeAcces;
use App\Services\Admin\AdminJournal;
use App\Services\Offres\CodeAccesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Codes d'accès, créés depuis l'espace d'administration.
 *
 * Chaque création et chaque révocation passe par le journal : un code ouvre l'application
 * entière, c'est un pouvoir qui doit laisser une trace.
 */
class AdminCodeController extends Controller
{
    public function __construct(
        private readonly CodeAccesService $codes,
        private readonly AdminJournal $journal,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CodeAcces::query()
                ->withCount('utilisationsListe')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
                ->map(fn (CodeAcces $code) => [
                    'id' => (int) $code->id,
                    'code' => $code->code,
                    'offre' => $code->offre->value,
                    'offre_libelle' => $code->offre->label(),
                    'duree_jours' => $code->duree_jours,
                    'utilisations' => (int) $code->utilisations,
                    'utilisations_max' => (int) $code->utilisations_max,
                    'expire_le' => $code->expire_le?->toISOString(),
                    'actif' => (bool) $code->actif,
                    'utilisable' => $code->estUtilisable(),
                    'note' => $code->note,
                    'created_at' => $code->created_at?->toISOString(),
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'offre' => ['required', Rule::in([Offre::Complet->value, Offre::Foyer->value])],
            'utilisations_max' => ['sometimes', 'integer', 'between:1,1000'],
            'duree_jours' => ['sometimes', 'nullable', 'integer', 'between:1,3650'],
            'expire_le' => ['sometimes', 'nullable', 'date', 'after:today'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $code = $this->codes->creer(
            $request->user(),
            Offre::from($valide['offre']),
            (int) ($valide['utilisations_max'] ?? 1),
            $valide['duree_jours'] ?? null,
            $valide['expire_le'] ?? null,
            $valide['note'] ?? null,
        );

        $this->journal->enregistrer(
            $request->user(),
            'creer_code_acces',
            'code_acces',
            $code->id,
            $code->code,
            $valide['note'] ?? 'Code d’accès '.$code->offre->label(),
        );

        return response()->json(['message' => 'Code créé.', 'data' => ['code' => $code->code]], 201);
    }

    public function revoke(Request $request, int $code): JsonResponse
    {
        $valide = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:255']]);
        $modele = CodeAcces::query()->findOrFail($code);

        $modele->forceFill(['actif' => false])->save();

        $this->journal->enregistrer(
            $request->user(),
            'revoquer_code_acces',
            'code_acces',
            $modele->id,
            $modele->code,
            $valide['motif'],
        );

        // Les accès déjà accordés ne sont pas repris : révoquer empêche de nouvelles
        // utilisations, cela ne punit pas ceux qui s'en sont servis de bonne foi.
        return response()->json(['message' => 'Code révoqué.']);
    }
}
