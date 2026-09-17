<?php

namespace App\Services\Sport;

/**
 * Générateur pseudo-aléatoire déterministe (LCG 32 bits) : mêmes entrées + même graine ⇒ même séance.
 * Indépendant de mt_rand() pour ne pas perturber le reste de l'application.
 */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0x7FFFFFFF) ?: 1;
    }

    public function next(): int
    {
        // Paramètres de Park–Miller (minstd).
        $this->state = (int) (($this->state * 48271) % 2147483647);

        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($this->next() % ($max - $min + 1));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        $items = array_values($items);

        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
