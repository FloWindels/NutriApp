<?php

namespace App\Services\Foods;

use App\Exceptions\OffUnavailableException;
use App\Models\Food;
use App\Services\OpenFoodFactsClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Catalogue d'aliments : recherche locale (LIKE échappé), résolution par code-barres
 * (candidats 12 ↔ 13 chiffres), rafraîchissement et import depuis Open Food Facts (brief §3.2).
 */
class FoodCatalog
{
    /** Délai au-delà duquel une fiche OFF est rafraîchie lors d'un scan. */
    public const REFRESH_DAYS = 90;

    /** Nombre minimal de résultats locaux en dessous duquel OFF est interrogé (off=1). */
    public const OFF_LOCAL_THRESHOLD = 5;

    /** Longueur minimale du terme pour interroger OFF. */
    public const OFF_MIN_TERM_LENGTH = 3;

    /** Colonnes de `food` alimentées par une fiche OFF normalisée. */
    private const OFF_COLUMNS = [
        'name', 'brand', 'image_url', 'calories', 'fat', 'carbs', 'proteins',
        'fiber', 'sugar', 'salt', 'per_unit', 'serving_size_g', 'serving_label',
        'category', 'allergens',
    ];

    public function __construct(private readonly OpenFoodFactsClient $off)
    {
    }

    // ------------------------------------------------------------------
    // Recherche textuelle
    // ------------------------------------------------------------------

    /**
     * Terme normalisé (minuscules, sans espaces superflus).
     */
    public static function term(?string $q): string
    {
        return mb_strtolower(trim((string) $q));
    }

    /**
     * Motif LIKE échappé (`%`, `_` et `\` deviennent littéraux) : `%terme%`.
     */
    public static function likePattern(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /**
     * Applique la recherche textuelle sur nom, marque ou code-barres.
     *
     * @param  Builder<Food>  $query
     * @return Builder<Food>
     */
    public static function applySearch(Builder $query, string $term): Builder
    {
        $like = self::likePattern($term);

        return $query->where(function (Builder $builder) use ($like) {
            $builder->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(brand) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("barcode LIKE ? ESCAPE '\\'", [$like]);
        });
    }

    /**
     * Requête de base d'une recherche (filtres q / barcode + ordre `is_verified desc, updated_at desc`).
     *
     * @return Builder<Food>
     */
    public function searchQuery(?string $q, ?string $barcode): Builder
    {
        $query = Food::query();

        $barcode = trim((string) $barcode);
        if ($barcode !== '') {
            $query->whereIn('barcode', self::barcodeCandidates($barcode));
        }

        $term = self::term($q);
        if ($term !== '') {
            self::applySearch($query, $term);
        }

        return $query->orderByDesc('is_verified')->orderByDesc('updated_at')->orderByDesc('id');
    }

    /**
     * Faut-il compléter par Open Food Facts ? (utilisateur connecté, peu de résultats, terme ≥ 3).
     */
    public static function shouldQueryOff(bool $offRequested, bool $authenticated, int $localTotal, string $term): bool
    {
        return $offRequested
            && $authenticated
            && $localTotal < self::OFF_LOCAL_THRESHOLD
            && mb_strlen($term) >= self::OFF_MIN_TERM_LENGTH;
    }

    /**
     * Interroge OFF et insère/actualise les fiches à code-barres numérique.
     * Retourne vrai si OFF a répondu (même sans résultat), faux s'il était indisponible.
     */
    public function importSearchResults(string $term): bool
    {
        try {
            $rows = $this->off->search($term);
        } catch (OffUnavailableException $e) {
            Log::info('Recherche Open Food Facts indisponible.', ['status' => $e->getCode()]);

            return false;
        }

        $this->upsertOffRows($rows);

        return true;
    }

    /**
     * Upsert des fiches OFF normalisées (clé `barcode`). Les fiches sans code numérique sont
     * ignorées, ainsi que celles dont le code correspond à un aliment local vérifié ou manuel.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return int nombre de lignes envoyées à l'upsert
     */
    public function upsertOffRows(array $rows): int
    {
        $byBarcode = [];
        foreach ($rows as $row) {
            $barcode = (string) ($row['barcode'] ?? '');
            if ($barcode === '' || ! ctype_digit($barcode)) {
                continue;
            }
            $byBarcode[$barcode] = $row; // dédoublonnage : la dernière fiche gagne
        }

        if ($byBarcode === []) {
            return 0;
        }

        // Les aliments locaux vérifiés ou saisis à la main gardent la main sur leurs données.
        $protected = Food::query()
            ->whereIn('barcode', array_keys($byBarcode))
            ->where(function (Builder $builder) {
                $builder->where('is_verified', true)->orWhere('source_type', '!=', 'open_food_facts');
            })
            ->pluck('barcode')
            ->all();

        foreach ($protected as $barcode) {
            unset($byBarcode[(string) $barcode]);
        }

        if ($byBarcode === []) {
            return 0;
        }

        $now = now();
        $payload = [];
        foreach ($byBarcode as $barcode => $row) {
            $payload[] = [
                'barcode' => (string) $barcode,
                ...self::offAttributes($row, forUpsert: true),
                'source_type' => 'open_food_facts',
                'source_fetched_at' => $now,
                'off_last_checked_at' => $now,
                'is_verified' => false,
                'created_by_user_id' => null,
            ];
        }

        Food::upsert(
            $payload,
            ['barcode'],
            [...self::OFF_COLUMNS, 'source_fetched_at', 'off_last_checked_at'],
        );

        return count($payload);
    }

    // ------------------------------------------------------------------
    // Code-barres
    // ------------------------------------------------------------------

    /**
     * Candidats dans l'ordre : code scanné ; 12 chiffres → « 0 » + code ; 13 chiffres
     * commençant par « 0 » → sans ce zéro.
     *
     * @return list<string>
     */
    public static function barcodeCandidates(string $code): array
    {
        $code = trim($code);
        $candidates = [$code];

        if (ctype_digit($code)) {
            if (strlen($code) === 12) {
                $candidates[] = '0'.$code;
            } elseif (strlen($code) === 13 && str_starts_with($code, '0')) {
                $candidates[] = substr($code, 1);
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));
    }

    /**
     * Aliment local correspondant à un code (premier candidat trouvé), ou null.
     */
    public function findLocalByBarcode(string $code): ?Food
    {
        $candidates = self::barcodeCandidates($code);
        if ($candidates === []) {
            return null;
        }

        $matches = Food::query()->whereIn('barcode', $candidates)->get()->keyBy('barcode');

        foreach ($candidates as $candidate) {
            if ($matches->has($candidate)) {
                return $matches->get($candidate);
            }
        }

        return null;
    }

    /**
     * Fiche OFF pour le premier candidat connu d'OFF (null si aucun).
     * Propage OffUnavailableException.
     *
     * @return array<string, mixed>|null
     */
    public function fetchFromOff(string $code): ?array
    {
        foreach (self::barcodeCandidates($code) as $candidate) {
            $product = $this->off->product($candidate);
            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Vrai si la fiche OFF locale doit être rafraîchie (jamais vérifiée ou > 90 jours).
     */
    public static function needsRefresh(Food $food): bool
    {
        if (! $food->isFromOpenFoodFacts()) {
            return false;
        }

        $checked = $food->off_last_checked_at;

        return $checked === null || $checked->lt(now()->subDays(self::REFRESH_DAYS));
    }

    /**
     * Rafraîchit une fiche OFF locale de façon synchrone. Une fiche vérifiée ne reçoit que
     * ses champs manquants ; une fiche non vérifiée est mise à jour avec les valeurs OFF
     * non nulles. Les échecs réseau sont ignorés (l'aliment local est renvoyé tel quel).
     */
    public function refreshFromOff(Food $food): Food
    {
        if (! self::needsRefresh($food) || $food->barcode === null) {
            return $food;
        }

        try {
            $product = $this->off->product((string) $food->barcode);
        } catch (OffUnavailableException $e) {
            Log::info('Rafraîchissement Open Food Facts ignoré (indisponible).', ['food_id' => $food->id]);

            return $food;
        }

        $changes = ['off_last_checked_at' => now()];

        if ($product !== null) {
            $fresh = self::offAttributes($product);
            foreach ($fresh as $column => $value) {
                if ($value === null) {
                    continue; // jamais d'écrasement par du vide
                }
                if ($food->is_verified && $food->{$column} !== null) {
                    continue; // fiche vérifiée : on complète seulement les trous
                }
                $changes[$column] = $value;
            }
            $changes['source_fetched_at'] = now();
        }

        $food->fill($changes)->save();

        return $food;
    }

    /**
     * Crée l'aliment local à partir d'une fiche OFF (course possible sur le code-barres unique).
     *
     * @param  array<string, mixed>  $product
     */
    public function createFromOff(array $product, string $scannedCode): Food
    {
        $barcode = (string) ($product['barcode'] ?? '');
        if ($barcode === '') {
            $barcode = trim($scannedCode);
        }

        $attributes = [
            'barcode' => $barcode,
            ...self::offAttributes($product),
            'source_type' => 'open_food_facts',
            'source_fetched_at' => now(),
            'off_last_checked_at' => now(),
            'is_verified' => false,
            'created_by_user_id' => null,
        ];

        try {
            return Food::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return Food::query()->where('barcode', $barcode)->firstOrFail();
        }
    }

    /**
     * Colonnes de `food` d'une fiche OFF normalisée (sans clé hors table).
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public static function offAttributes(array $product, bool $forUpsert = false): array
    {
        $attributes = Arr::only($product, self::OFF_COLUMNS);

        foreach (self::OFF_COLUMNS as $column) {
            $attributes[$column] = $attributes[$column] ?? null;
        }

        if ($forUpsert) {
            // L'upsert contourne les casts : le json est encodé à la main.
            $attributes['allergens'] = $attributes['allergens'] === null ? null : json_encode($attributes['allergens']);
            $attributes['per_unit'] = $attributes['per_unit'] ?? '100g';
        }

        return $attributes;
    }

    /**
     * Résout un ingrédient (code-barres puis nom) vers un aliment local.
     */
    public function resolveIngredient(?string $ean, ?string $name): ?Food
    {
        $ean = trim((string) $ean);
        if ($ean !== '') {
            $food = $this->findLocalByBarcode($ean);
            if ($food !== null) {
                return $food;
            }
        }

        $term = self::term($name);
        if ($term === '') {
            return null;
        }

        $like = self::likePattern($term);

        return Food::query()
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
            ->orderByDesc('is_verified')
            ->orderByRaw('LENGTH(name) ASC')
            ->orderByDesc('updated_at')
            ->first();
    }
}
