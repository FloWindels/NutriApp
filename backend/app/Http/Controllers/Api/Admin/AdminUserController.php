<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Offre;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des comptes, sous une règle qui tient en une phrase :
 * **l'administrateur voit l'enveloppe, jamais le contenu.**
 *
 * Ce qu'il peut : lister, chercher, suspendre, lever une suspension, poser une offre. Ce qu'il ne peut pas, et
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
                'offre', 'offre_expire_le',
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

        $offresDesFoyers = $this->offresDesFoyers(collect($page->items()));
        $membresCouverts = $this->membresCouverts(collect($page->items()));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verifie' => $user->email_verified_at !== null,
                'role' => $user->role->value,
                'dans_un_foyer' => $user->household_id !== null,
                'offre' => ($user->offre ?? Offre::Gratuit)->value,
                'offre_libelle' => ($user->offre ?? Offre::Gratuit)->label(),
                'offre_expire_le' => $user->offre_expire_le?->toISOString(),
                // L'échéance se joue à l'heure près. La laisser recalculer au navigateur, qui ne
                // voit qu'une date, ferait afficher une offre encore active des heures après que
                // le serveur l'a coupée. C'est donc le serveur qui tranche, une bonne fois.
                'offre_valide' => $user->offreValide()->value,
                'offre_effective' => $this->offreEffective($user, $offresDesFoyers)->value,
                'offre_effective_libelle' => $this->offreEffective($user, $offresDesFoyers)->label(),
                // Rétrograder le propriétaire d'un foyer coupe aussi ses membres. Sans ce compte,
                // l'administrateur ne peut pas savoir qu'il s'apprête à débrancher quatre personnes.
                'foyer_membres_couverts' => (int) ($membresCouverts[$user->id] ?? 0),
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

    public function suspend(Request $request, string $user): JsonResponse
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

    public function restore(Request $request, string $user): JsonResponse
    {
        $motif = $this->motif($request);
        $cible = User::query()->findOrFail($user);

        $cible->forceFill(['suspendu_le' => null, 'suspension_motif' => null])->save();

        $this->journal->enregistrer($request->user(), 'lever_suspension', 'user', $cible->id, $cible->email, $motif);

        return response()->json(['message' => 'Suspension levée.']);
    }

    /**
     * Pose une offre à la main.
     *
     * Aucun paiement n'est branché : c'est ici, ou par un code d'accès, qu'une offre s'ouvre.
     * Le geste est réversible et ne touche qu'aux deux colonnes de l'offre — repasser un compte
     * en gratuit lui retire des fonctions, jamais ses données.
     */
    public function offre(Request $request, string $user): JsonResponse
    {
        $valide = $request->validate([
            'offre' => ['required', Rule::in(Offre::values())],
            'duree_jours' => ['nullable', 'integer', 'between:1,3650'],
            'motif' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $nouvelle = Offre::from($valide['offre']);
        $duree = $valide['duree_jours'] ?? null;

        if ($nouvelle === Offre::Gratuit && $duree !== null) {
            throw ValidationException::withMessages([
                'duree_jours' => ['Une offre gratuite n’expire pas : laisse la durée vide.'],
            ]);
        }

        $cible = User::query()->findOrFail($user);
        $ancienne = $cible->offre ?? Offre::Gratuit;
        $etaitEchue = $cible->offreValide() !== $ancienne;

        $cible->forceFill([
            'offre' => $nouvelle,
            // (int) délibéré : la règle « integer » laisse passer true, et Carbon::addDays(true)
            // rend la date inchangée — l'échéance tombait donc à l'instant de la requête.
            'offre_expire_le' => $duree === null ? null : now()->addDays((int) $duree),
        ])->save();

        $this->journal->enregistrer(
            $request->user(),
            'changer_offre',
            'user',
            $cible->id,
            $this->libelleDuChangement($cible, $ancienne, $etaitEchue, $nouvelle),
            $valide['motif'],
        );

        return response()->json([
            'message' => 'Offre mise à jour.',
            'data' => [
                'offre' => $nouvelle->value,
                'offre_libelle' => $nouvelle->label(),
                'offre_expire_le' => $cible->offre_expire_le?->toISOString(),
            ],
        ]);
    }

    /**
     * Offres des foyers de la page, en une seule requête.
     *
     * User::offreEffective() va chercher le propriétaire du foyer compte par compte : sur vingt-cinq
     * lignes, cela ferait vingt-cinq requêtes. On les ramène toutes d'un coup, indexées par foyer.
     *
     * @param  Collection<int, User>  $comptes
     * @return Collection<int, Offre>
     */
    private function offresDesFoyers(Collection $comptes): Collection
    {
        $foyers = $comptes->pluck('household_id')->filter()->unique()->values();

        if ($foyers->isEmpty()) {
            return collect();
        }

        return User::query()
            ->select(['users.offre', 'users.offre_expire_le', 'households.id as foyer_id'])
            ->join('households', 'households.owner_id', '=', 'users.id')
            ->whereIn('households.id', $foyers)
            ->get()
            ->mapWithKeys(fn (User $proprietaire) => [(int) $proprietaire->foyer_id => $proprietaire->offreValide()]);
    }

    /**
     * Même règle que User::offreEffective(), mais en mémoire : la meilleure des deux entre la
     * sienne et celle du propriétaire de son foyer, une offre expirée retombant sur la gratuite.
     *
     * @param  Collection<int, Offre>  $offresDesFoyers
     */
    private function offreEffective(User $user, Collection $offresDesFoyers): Offre
    {
        $sienne = $user->offreValide();

        $celleDuFoyer = $user->household_id !== null
            ? ($offresDesFoyers[$user->household_id] ?? Offre::Gratuit)
            : Offre::Gratuit;

        return $celleDuFoyer->rang() > $sienne->rang() ? $celleDuFoyer : $sienne;
    }

    /**
     * Ce que la ligne du journal doit dire pour rester lisible dans six mois, sans recroiser
     * aucune autre table : d'où l'on vient, si ce droit était déjà éteint, où l'on va, et
     * jusqu'à quand.
     *
     * L'adresse est raccourcie en tête plutôt qu'en queue : AdminJournal tronque à 255, et une
     * adresse à rallonge emporterait sinon exactement l'information que ce libellé porte.
     */
    private function libelleDuChangement(User $cible, Offre $ancienne, bool $etaitEchue, Offre $nouvelle): string
    {
        $echeance = $cible->offre_expire_le === null
            ? 'sans échéance'
            : 'jusqu’au '.$cible->offre_expire_le->format('d/m/Y');

        return mb_substr($cible->email, 0, 120).' : '
            .$ancienne->label().($etaitEchue ? ' (échue)' : '')
            .' → '.$nouvelle->label().' · '.$echeance;
    }

    /**
     * Combien de comptes dépendent de l'offre de chacun, en une requête pour toute la page.
     *
     * Un foyer est porté par son propriétaire : quand son offre tombe, celle de ses membres
     * tombe avec. Le panneau doit pouvoir le dire AVANT le geste, pas après.
     *
     * @param  Collection<int, User>  $comptes
     * @return Collection<int, int>
     */
    private function membresCouverts(Collection $comptes): Collection
    {
        $ids = $comptes->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->select(['households.owner_id', DB::raw('COUNT(*) as membres')])
            ->join('households', 'households.id', '=', 'users.household_id')
            ->whereIn('households.owner_id', $ids)
            ->whereColumn('users.id', '!=', 'households.owner_id')
            ->groupBy('households.owner_id')
            ->get()
            ->mapWithKeys(fn ($ligne) => [(int) $ligne->owner_id => (int) $ligne->membres]);
    }

    /** Un motif écrit est exigé : une action sans raison n'est pas défendable. */
    private function motif(Request $request): string
    {
        $valide = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:255']]);

        return $valide['motif'];
    }
}
