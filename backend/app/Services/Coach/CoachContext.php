<?php

namespace App\Services\Coach;

use App\Enums\MealType;
use App\Models\Meal;
use App\Models\Profile;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WorkoutSession;
use App\Services\MealCalculator;
use App\Support\Clock;
use App\Support\StockScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Données d'entrée du coach pour une journée : construites une seule fois (le tableau de bord
 * peut fournir ce qu'il a déjà chargé pour rester sous le budget de requêtes).
 */
final class CoachContext
{
    public User $user;

    public string $date;

    public string $today;

    public bool $isToday;

    /** Heure locale (0–23) ; 24 pour une date passée, 0 pour une date future. */
    public int $hour;

    public Carbon $now;

    public string $timezone;

    public ?Profile $profile;

    public ?UserSetting $settings;

    /** Résumé de journée (MealCalculator::daySummary) avec logged_types en chaînes. */
    public array $summary;

    /** @var Collection<int, Meal> repas du jour avec items (+ items.food) */
    public Collection $meals;

    /** @var Collection<int, StockItem> articles visibles (stock + food chargés), épuisés inclus */
    public Collection $stockItems;

    /** @var Collection<int, WorkoutSession> séances du jour */
    public Collection $sessions;

    public int $joursAlerte;

    public ?string $regime;

    public bool $isMinor;

    public string $situation;

    public bool $hasProfile;

    public float $poids;

    /**
     * @param  array{summary?: array<string, mixed>, meals?: Collection<int, Meal>, stockItems?: Collection<int, StockItem>, sessions?: Collection<int, WorkoutSession>}  $preloaded
     */
    public static function build(User $user, string $date, MealCalculator $calculator, array $preloaded = []): self
    {
        $user->loadMissing(['profile', 'settings']);

        $ctx = new self;
        $ctx->user = $user;
        $ctx->date = $date;
        $ctx->today = Clock::today($user);
        $ctx->isToday = $date === $ctx->today;
        $ctx->now = Clock::now($user);
        $ctx->timezone = Clock::timezone($user);
        $ctx->hour = $ctx->isToday ? Clock::hour($user) : ($date < $ctx->today ? 24 : 0);
        $ctx->profile = $user->getRelation('profile');
        $ctx->settings = $user->getRelation('settings');

        $summary = $preloaded['summary'] ?? $calculator->daySummary($user, $date);
        $summary['logged_types'] = self::normalizeTypes($summary['logged_types'] ?? []);
        $summary['next_meal_type'] = $calculator->nextMealType($summary['logged_types'], $ctx->hour);
        $ctx->summary = $summary;

        $ctx->meals = $preloaded['meals'] ?? Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->with(['items.food'])
            ->get();

        $ctx->stockItems = $preloaded['stockItems'] ?? StockScope::items($user)
            ->with(['stock', 'food'])
            ->get();

        $ctx->sessions = $preloaded['sessions'] ?? WorkoutSession::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->get();

        $ctx->joursAlerte = max(1, (int) ($ctx->settings?->jours_alerte_peremption ?? 3));
        $ctx->regime = $ctx->profile?->regime_alimentaire;
        $ctx->isMinor = $ctx->profile?->isMinor() ?? false;
        $ctx->situation = (string) ($ctx->profile?->situation_particuliere ?? 'aucune');
        $ctx->hasProfile = (bool) ($summary['has_profile'] ?? false);
        $ctx->poids = (float) ($ctx->profile?->poids ?? 70.0);

        return $ctx;
    }

    /**
     * Articles non épuisés (quantité > 0).
     *
     * @return Collection<int, StockItem>
     */
    public function availableStock(): Collection
    {
        return $this->stockItems->filter(fn (StockItem $item) => ! $item->isDepleted())->values();
    }

    /**
     * Jours restants avant péremption (négatif si dépassée), null sans date.
     */
    public function daysLeft(StockItem $item): ?int
    {
        if ($item->expires_at === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($this->today)->diffInDays(CarbonImmutable::parse($item->expires_at->format('Y-m-d')), false);
    }

    public function objectif(): string
    {
        return (string) ($this->profile?->objectif_type ?? 'maintenir');
    }

    /**
     * Vrai si les règles de budget s'appliquent (profil complet, adulte, situation « aucune »).
     */
    public function budgetRulesAllowed(): bool
    {
        return $this->hasProfile && ! $this->isMinor && $this->situation === 'aucune';
    }

    /**
     * Fenêtre alimentaire du jeûne intermittent (config) ou null si le régime n'en a pas.
     *
     * @return array{0: string, 1: string}|null
     */
    public function fastingWindow(): ?array
    {
        if ($this->regime !== 'jeune_intermittent') {
            return null;
        }
        $f = (array) config('diets.jeune_intermittent.regles.fenetre_alimentaire', ['debut' => '12:00', 'fin' => '20:00']);

        return [(string) ($f['debut'] ?? '12:00'), (string) ($f['fin'] ?? '20:00')];
    }

    /**
     * Heure locale d'un repas (HH:MM) : consumed_at ou heure par défaut du type.
     */
    public function mealTime(Meal $meal): string
    {
        if ($meal->consumed_at !== null) {
            return $meal->consumed_at->copy()->setTimezone($this->timezone)->format('H:i');
        }

        $type = $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type;

        return MealCalculator::defaultHour($type);
    }

    /**
     * @param  array<int, mixed>  $types
     * @return list<string>
     */
    public static function normalizeTypes(array $types): array
    {
        return array_values(array_unique(array_map(
            fn ($t) => $t instanceof MealType ? $t->value : (string) $t,
            $types
        )));
    }
}
