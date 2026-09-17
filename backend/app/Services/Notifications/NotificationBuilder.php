<?php

namespace App\Services\Notifications;

use App\Enums\ExpiryKind;
use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Enums\RecommendationStatus;
use App\Enums\SessionStatus;
use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\NotificationRead;
use App\Models\Recommendation;
use App\Models\SportPlan;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WorkoutSession;
use App\Support\Clock;
use App\Support\OwnerScope;
use App\Support\StockScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Notifications générées à la volée (brief §14) — rien n'est stocké sauf les marques de lecture
 * (`notification_reads`). Sources : articles de stock qui expirent (selon jours_alerte_peremption,
 * si notif_peremption), articles périmés, repas planifiés du jour non enregistrés (si
 * notif_rappel_repas), séances / entrées du calendrier sportif du jour (si notif_rappel_sport),
 * recommandations de priorité 1 (lecture seule : le moteur n'est jamais lancé ici).
 *
 * Chaque notification porte une clé stable ({type}_{hash court}) pour que la lecture survive
 * aux régénérations.
 */
class NotificationBuilder
{
    public const TYPE_STOCK_PERIME = 'stock_perime';
    public const TYPE_STOCK_PEREMPTION = 'stock_peremption';
    public const TYPE_RAPPEL_REPAS = 'rappel_repas';
    public const TYPE_RAPPEL_SPORT = 'rappel_sport';
    public const TYPE_RECOMMANDATION = 'recommandation';

    /** Au-delà, une DDM dépassée n'est plus signalée (produit probablement jeté). */
    public const DDM_JOURS_MAX = 30;

    /**
     * @return array{data: list<array<string, mixed>>, unread_count: int}
     */
    public function build(User $user): array
    {
        $settings = UserSetting::query()->firstOrCreate(['user_id' => $user->id]);
        $today = Clock::today($user);

        $items = [
            ...$this->stockNotifications($user, $settings, $today),
            ...$this->recommendationNotifications($user, $today),
            ...$this->mealNotifications($user, $settings, $today),
            ...$this->sportNotifications($user, $settings, $today),
        ];

        $read = NotificationRead::query()
            ->where('user_id', $user->id)
            ->pluck('key')
            ->flip()
            ->all();

        $unread = 0;

        foreach ($items as &$item) {
            $item['read'] = isset($read[$item['key']]);

            if (! $item['read']) {
                $unread++;
            }
        }
        unset($item);

        return ['data' => array_values($items), 'unread_count' => $unread];
    }

    /**
     * Marque une clé comme lue (idempotent).
     */
    public function markRead(User $user, string $key): void
    {
        $this->markManyRead($user, [$key]);
    }

    /**
     * Marque toutes les notifications actuellement générées comme lues ; retourne le nombre marqué.
     */
    public function markAllRead(User $user): int
    {
        $keys = array_column($this->build($user)['data'], 'key');
        $this->markManyRead($user, $keys);

        return count($keys);
    }

    /**
     * @param  list<string>  $keys
     */
    private function markManyRead(User $user, array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && $k !== '')));

        if ($keys === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (string $key): array => [
            'user_id' => (int) $user->id,
            'key' => $key,
            'read_at' => $now,
        ], $keys);

        DB::table('notification_reads')->upsert($rows, ['user_id', 'key'], ['read_at']);
    }

    // ------------------------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function stockNotifications(User $user, UserSetting $settings, string $today): array
    {
        $items = StockScope::items($user)
            ->with('stock:id,name')
            ->whereNotNull('expires_at')
            ->where('quantity', '>', 0)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();

        $todayDate = CarbonImmutable::parse($today);
        $joursAlerte = max(0, (int) $settings->jours_alerte_peremption);

        $expired = [];
        $expiring = [];

        foreach ($items as $item) {
            /** @var StockItem $item */
            $expiresAt = $item->expires_at->toDateString();
            $daysLeft = (int) $todayDate->diffInDays(CarbonImmutable::parse($expiresAt), false);
            $label = $this->stockLabel($item);
            $action = ['kind' => 'ouvrir_stock', 'stock_item_ids' => [(int) $item->id]];

            if ($daysLeft < 0) {
                $isDdm = $item->expiry_kind === ExpiryKind::Ddm->value;

                if ($isDdm && -$daysLeft > self::DDM_JOURS_MAX) {
                    continue;
                }

                $expired[] = $this->notification(
                    self::TYPE_STOCK_PERIME,
                    [$item->id, $expiresAt],
                    $isDdm ? 'DDM dépassée' : 'Produit périmé',
                    $isDdm
                        ? sprintf('%s a dépassé sa date de durabilité minimale (%s) : souvent encore consommable, vérifie l’aspect et l’odeur.', $label, $this->frDate($expiresAt))
                        : sprintf('%s est périmé depuis le %s : vérifie-le avant toute consommation.', $label, $this->frDate($expiresAt)),
                    $expiresAt,
                    $action
                );

                continue;
            }

            if ($settings->notif_peremption && $daysLeft <= $joursAlerte) {
                $expiring[] = $this->notification(
                    self::TYPE_STOCK_PEREMPTION,
                    [$item->id, $expiresAt],
                    'À consommer bientôt',
                    sprintf('%s expire %s.', $label, $this->quand($daysLeft)),
                    $expiresAt,
                    $action
                );
            }
        }

        return [...$expired, ...$expiring];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recommendationNotifications(User $user, string $today): array
    {
        $recos = Recommendation::query()
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->where('priority', 1)
            ->where('status', '!=', RecommendationStatus::Ignoree->value)
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($recos as $reco) {
            /** @var Recommendation $reco */
            $actions = is_array($reco->actions) ? array_values($reco->actions) : [];

            $out[] = $this->notification(
                self::TYPE_RECOMMANDATION,
                [$reco->dedupe_key, $today],
                (string) $reco->title,
                (string) $reco->message,
                $today,
                $actions !== [] && is_array($actions[0]) ? $actions[0] : null
            );
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mealNotifications(User $user, UserSetting $settings, string $today): array
    {
        if (! $settings->notif_rappel_repas) {
            return [];
        }

        $plans = OwnerScope::apply(MealPlan::query(), $user)
            ->where('date', $today)
            ->where('status', PlanStatus::Prevu->value)
            ->whereNull('meal_id')
            ->orderBy('id')
            ->get();

        if ($plans->isEmpty()) {
            return [];
        }

        $loggedTypes = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->whereHas('items')
            ->get(['id', 'type'])
            ->map(fn (Meal $m) => $m->type instanceof MealType ? $m->type->value : (string) $m->type)
            ->flip()
            ->all();

        $out = [];

        foreach ($plans as $plan) {
            /** @var MealPlan $plan */
            if (isset($loggedTypes[$plan->meal_type])) {
                continue;
            }

            $type = MealType::tryFrom((string) $plan->meal_type);
            $typeLabel = $type?->label() ?? ucfirst((string) $plan->meal_type);

            $out[] = $this->notification(
                self::TYPE_RAPPEL_REPAS,
                [$plan->id, $today],
                'Repas prévu',
                sprintf('%s : « %s » est prévu aujourd’hui et n’est pas encore enregistré.', $typeLabel, $plan->title),
                $today,
                ['kind' => 'ouvrir_planificateur', 'date' => $today, 'meal_plan_id' => (int) $plan->id]
            );
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sportNotifications(User $user, UserSetting $settings, string $today): array
    {
        if (! $settings->notif_rappel_sport) {
            return [];
        }

        $out = [];

        $sessions = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->where('status', SessionStatus::Prevue->value)
            ->orderBy('planned_at')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            /** @var WorkoutSession $session */
            $heure = $this->heure($session->planned_at);

            $out[] = $this->notification(
                self::TYPE_RAPPEL_SPORT,
                ['session', $session->id, $today],
                'Séance prévue',
                sprintf('%s (%d min)%s : pense à la lancer depuis l’onglet Sport.', $session->title, (int) $session->duration_min, $heure ? ' à '.$heure : ' aujourd’hui'),
                $today,
                ['kind' => 'ouvrir_seance', 'session_id' => (int) $session->id]
            );
        }

        $plans = SportPlan::query()
            ->where('user_id', $user->id)
            ->where('date', $today)
            ->where('status', PlanStatus::Prevu->value)
            ->whereNull('session_id')
            ->orderBy('planned_at')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            /** @var SportPlan $plan */
            $heure = $this->heure($plan->planned_at);

            $out[] = $this->notification(
                self::TYPE_RAPPEL_SPORT,
                ['plan', $plan->id, $today],
                'Sport prévu',
                sprintf('%s · %d min%s : n’oublie pas d’enregistrer ta séance une fois faite.', $plan->sport_name, (int) $plan->planned_duration_min, $heure ? ' à '.$heure : ' aujourd’hui'),
                $today,
                ['kind' => 'ouvrir_calendrier', 'date' => $today, 'sport_plan_id' => (int) $plan->id]
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $identity  éléments qui rendent la clé stable (ids, dates…)
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>
     */
    private function notification(string $type, array $identity, string $title, string $message, string $date, ?array $action): array
    {
        return [
            'key' => self::key($type, $identity),
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'date' => $date,
            'read' => false,
            'action' => $action,
        ];
    }

    /**
     * Clé stable : `{type}_{16 hex}` (≤ 64 caractères, alphabet [a-z0-9_]).
     *
     * @param  list<mixed>  $identity
     */
    public static function key(string $type, array $identity): string
    {
        return $type.'_'.substr(sha1($type.'|'.implode('|', array_map('strval', $identity))), 0, 16);
    }

    private function stockLabel(StockItem $item): string
    {
        $label = trim((string) $item->food_name);

        if ($label === '') {
            $label = 'Un article';
        }

        $stockName = $item->relationLoaded('stock') ? $item->getRelation('stock')?->name : null;

        return $stockName ? sprintf('%s (%s)', $label, $stockName) : $label;
    }

    private function quand(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0 => 'aujourd’hui',
            $daysLeft === 1 => 'demain',
            default => sprintf('dans %d jours', $daysLeft),
        };
    }

    private function frDate(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('fr')->translatedFormat('j F Y');
    }

    private function heure(mixed $time): ?string
    {
        if (! is_string($time) || strlen($time) < 5) {
            return null;
        }

        return substr($time, 0, 5);
    }
}
