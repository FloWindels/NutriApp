<?php

namespace App\Services\Admin;

use App\Models\Food;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\Scopes\NonMasqueScope;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques de l'application.
 *
 * Deux règles tiennent ce service. Tout est **agrégé** : aucune méthode ne renvoie une ligne
 * nominative, et aucune ne touche au contenu des journaux alimentaires. Et tout est calculé en
 * SQL, jamais en bouclant sur les jours en PHP : le tableau de bord doit rester instantané
 * quand la base grossit.
 */
class AdminStatsService
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $aujourdhui = now()->toDateString();

        return [
            'comptes' => $this->comptes($aujourdhui),
            'activite' => $this->activite(),
            'usage' => $this->usage($aujourdhui),
            'contenus' => $this->contenus(),
            'ia' => $this->ia(),
        ];
    }

    /** @return array<string, mixed> */
    private function comptes(string $aujourdhui): array
    {
        $depuis7 = date('Y-m-d', strtotime($aujourdhui.' -7 days'));
        $depuis30 = date('Y-m-d', strtotime($aujourdhui.' -30 days'));

        return [
            'total' => User::query()->count(),
            'nouveaux_7j' => User::query()->where('created_at', '>=', $depuis7)->count(),
            'nouveaux_30j' => User::query()->where('created_at', '>=', $depuis30)->count(),
            'avec_profil' => DB::table('profiles')->distinct()->count('user_id'),
            'suspendus' => User::query()->whereNotNull('suspendu_le')->count(),
            // Combien de personnes doivent encore prendre connaissance du texte en vigueur.
            'par_version_cgu' => User::query()
                ->selectRaw('cgu_version, COUNT(*) as total')
                ->groupBy('cgu_version')
                ->pluck('total', 'cgu_version')
                ->all(),
        ];
    }

    /**
     * Activité, mesurée sur les jetons.
     *
     * Limite assumée : un jeton est purgé lorsque sa date de CRÉATION dépasse la durée de vie
     * configurée. La fenêtre observable est donc bornée par cette durée, et un chiffre sur
     * quatre-vingt-dix jours serait faux. L'interface affiche cette limite.
     *
     * @return array<string, mixed>
     */
    private function activite(): array
    {
        $fenetreJours = (int) ceil(((int) config('sanctum.expiration', 43200)) / 1440);

        return [
            'actifs_7j' => $this->jetonsUtilisesDepuis(7),
            'actifs_30j' => $this->jetonsUtilisesDepuis(30),
            'fenetre_max_jours' => $fenetreJours,
        ];
    }

    private function jetonsUtilisesDepuis(int $jours): int
    {
        return (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', now()->subDays($jours))
            ->distinct()
            ->count('tokenable_id');
    }

    /** @return array<string, mixed> */
    private function usage(string $aujourdhui): array
    {
        $depuis30 = date('Y-m-d', strtotime($aujourdhui.' -30 days'));

        return [
            'repas_30j' => Meal::query()->where('date', '>=', $depuis30)->count(),
            // Une seule requête groupée : jamais une boucle de trente requêtes.
            'repas_par_jour' => Meal::query()
                ->selectRaw('date, COUNT(*) as total')
                ->where('date', '>=', $depuis30)
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('total', 'date')
                ->all(),
            'seances_par_statut' => WorkoutSession::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function contenus(): array
    {
        return [
            'aliments_crees' => Food::query()->whereNotNull('created_by_user_id')->count(),
            'aliments_masques' => Food::query()->withoutGlobalScope(NonMasqueScope::class)
                ->whereNotNull('masque_le')->count(),
            'recettes' => Recipe::query()->count(),
            'recettes_publiques' => Recipe::query()->where('is_public', true)->count(),
            'recettes_masquees' => Recipe::query()->withoutGlobalScope(NonMasqueScope::class)
                ->whereNotNull('masque_le')->count(),
        ];
    }

    /**
     * Usage de l'IA, lu sur les séances enregistrées.
     *
     * Limite assumée : les échecs de génération ne sont pas comptés. Ils ne laissent aujourd'hui
     * qu'une entrée dans les journaux applicatifs, et une séance produite par repli est
     * enregistrée comme « règles » sans qu'on sache si l'IA a échoué ou n'était pas configurée.
     *
     * @return array<string, mixed>
     */
    private function ia(): array
    {
        return [
            'seances_par_origine' => WorkoutSession::query()
                ->selectRaw('generated_by, COUNT(*) as total')
                ->groupBy('generated_by')
                ->pluck('total', 'generated_by')
                ->all(),
            'modeles' => WorkoutSession::query()
                ->whereNotNull('llm_model')
                ->selectRaw('llm_model, COUNT(*) as total')
                ->groupBy('llm_model')
                ->pluck('total', 'llm_model')
                ->all(),
        ];
    }
}
