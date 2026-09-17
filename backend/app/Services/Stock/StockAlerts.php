<?php

namespace App\Services\Stock;

use App\Enums\ExpiryKind;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use App\Support\StockScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Alertes de stock (brief §6.2) : péremption proche (fenêtre = user_settings.jours_alerte_peremption),
 * périmés et stock bas (quantity <= min_quantity, ou épuisé). Un article sans date de péremption
 * n'est jamais « bientôt périmé ».
 */
class StockAlerts
{
    public const DEFAULT_WINDOW_DAYS = 3;

    public const STATUS_OK = 'ok';
    public const STATUS_BIENTOT = 'bientot';
    public const STATUS_AUJOURDHUI = 'aujourdhui';
    public const STATUS_PERIME = 'perime';
    public const STATUS_DDM_DEPASSEE = 'ddm_depassee';
    public const STATUS_INCONNU = 'inconnu';

    /** @var array<int, int> cache par requête (id utilisateur → fenêtre en jours) */
    private static array $windows = [];

    // ------------------------------------------------------------------------------------
    // Contexte utilisateur
    // ------------------------------------------------------------------------------------

    /**
     * Fenêtre d'alerte (jours) de l'utilisateur : user_settings.jours_alerte_peremption, défaut 3.
     */
    public static function windowFor(User $user): int
    {
        $key = (int) $user->getKey();

        if ($key > 0 && isset(self::$windows[$key])) {
            return self::$windows[$key];
        }

        $days = null;

        if ($user->relationLoaded('settings')) {
            $days = $user->getRelation('settings')?->jours_alerte_peremption;
        } elseif ($key > 0) {
            $days = UserSetting::query()->where('user_id', $key)->value('jours_alerte_peremption');
        }

        $days = is_numeric($days) ? max(0, (int) $days) : self::DEFAULT_WINDOW_DAYS;

        if ($key > 0) {
            self::$windows[$key] = $days;
        }

        return $days;
    }

    /**
     * Vide le cache par requête (tests, changement de paramètres).
     */
    public static function forget(?User $user = null): void
    {
        if ($user === null) {
            self::$windows = [];

            return;
        }

        unset(self::$windows[(int) $user->getKey()]);
    }

    // ------------------------------------------------------------------------------------
    // Calculs unitaires (purs)
    // ------------------------------------------------------------------------------------

    /**
     * Jours restants avant péremption (négatif si dépassée), null sans date.
     */
    public static function daysLeft(StockItem $item, string $today): ?int
    {
        $expiresAt = $item->expires_at;
        if ($expiresAt === null) {
            return null;
        }

        $expiry = CarbonImmutable::parse($expiresAt instanceof \DateTimeInterface ? $expiresAt->format('Y-m-d') : (string) $expiresAt)->startOfDay();
        $ref = CarbonImmutable::parse($today)->startOfDay();

        return (int) $ref->diffInDays($expiry, false);
    }

    /**
     * Statut de péremption : ok | bientot | aujourdhui | perime | ddm_depassee | inconnu.
     */
    public static function expiryStatus(StockItem $item, string $today, int $window): string
    {
        $daysLeft = self::daysLeft($item, $today);

        if ($daysLeft === null) {
            return self::STATUS_INCONNU;
        }

        if ($daysLeft < 0) {
            return $item->expiry_kind === ExpiryKind::Ddm->value
                ? self::STATUS_DDM_DEPASSEE
                : self::STATUS_PERIME;
        }

        if ($daysLeft === 0) {
            return self::STATUS_AUJOURDHUI;
        }

        return $daysLeft <= $window ? self::STATUS_BIENTOT : self::STATUS_OK;
    }

    public static function isDepleted(StockItem $item): bool
    {
        return (float) $item->quantity <= 0 || $item->depleted_at !== null;
    }

    /**
     * Bientôt périmé : 0 <= jours restants <= fenêtre (les articles épuisés sont ignorés).
     */
    public static function isExpiring(StockItem $item, string $today, int $window): bool
    {
        if (self::isDepleted($item)) {
            return false;
        }

        $daysLeft = self::daysLeft($item, $today);

        return $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= $window;
    }

    /**
     * Périmé (DLC) ou DDM dépassée (les articles épuisés sont ignorés).
     */
    public static function isExpired(StockItem $item, string $today): bool
    {
        if (self::isDepleted($item)) {
            return false;
        }

        $daysLeft = self::daysLeft($item, $today);

        return $daysLeft !== null && $daysLeft < 0;
    }

    /**
     * Stock bas : quantité <= quantité minimale, ou épuisé.
     */
    public static function isLow(StockItem $item): bool
    {
        if (self::isDepleted($item)) {
            return true;
        }

        return $item->min_quantity !== null && (float) $item->quantity <= (float) $item->min_quantity;
    }

    // ------------------------------------------------------------------------------------
    // Sur un ensemble d'articles
    // ------------------------------------------------------------------------------------

    /**
     * Classe des articles déjà chargés.
     *
     * @param  Collection<int, StockItem>  $items
     * @return array{expiring: Collection<int, StockItem>, expired: Collection<int, StockItem>, low: Collection<int, StockItem>}
     */
    public function classify(Collection $items, User $user): array
    {
        $today = Clock::today($user);
        $window = self::windowFor($user);

        return [
            'expiring' => $items->filter(fn (StockItem $item) => self::isExpiring($item, $today, $window))->values(),
            'expired' => $items->filter(fn (StockItem $item) => self::isExpired($item, $today))->values(),
            'low' => $items->filter(fn (StockItem $item) => self::isLow($item))->values(),
        ];
    }

    /**
     * Compteurs pour GET /stocks.
     *
     * @param  Collection<int, StockItem>  $items
     * @return array{expiring_count: int, expired_count: int, low_count: int}
     */
    public function counts(Collection $items, User $user): array
    {
        $classified = $this->classify($items, $user);

        return [
            'expiring_count' => $classified['expiring']->count(),
            'expired_count' => $classified['expired']->count(),
            'low_count' => $classified['low']->count(),
        ];
    }

    /**
     * Charge tous les articles visibles par l'utilisateur (lieu + aliment), épuisés compris.
     *
     * @return Collection<int, StockItem>
     */
    public function itemsFor(User $user): Collection
    {
        return StockScope::items($user)
            ->with(['stock', 'food'])
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Alertes complètes pour GET /stocks/alerts.
     *
     * @return array{expiring: Collection<int, StockItem>, expired: Collection<int, StockItem>, low: Collection<int, StockItem>}
     */
    public function forUser(User $user): array
    {
        return $this->classify($this->itemsFor($user), $user);
    }
}
