<?php

namespace App\Services\Llm;

use App\Exceptions\LlmBusyException;
use Illuminate\Support\Facades\Log;

/**
 * Régule l'accès au modèle local, partagé par tous les comptes sur un seul GPU.
 *
 * Au plus `slots` générations tournent à la fois ; jusqu'à `queue` demandes de plus attendent
 * leur tour, `wait` secondes au maximum. Au-delà, la demande est refusée tout de suite
 * (LlmBusyException) plutôt que d'allonger une file que personne ne verrait avancer : le
 * reste de l'application reste fluide, et la personne sait qu'elle peut réessayer.
 *
 * Places et tickets sont des verrous flock() sur des fichiers : le système les libère à la mort
 * du processus, même tué par un délai dépassé, donc aucune place ne reste bloquée. Ils ne valent
 * que pour les processus d'une même machine — l'API tourne dans un seul conteneur.
 */
class OllamaGate
{
    private const POLL_MICROSECONDS = 200_000;

    /**
     * @template T
     *
     * @param  int  $timeout  délai HTTP de l'appel, en secondes
     * @param  callable(): T  $call
     * @return T
     */
    public static function run(int $timeout, callable $call): mixed
    {
        $slots = max(1, (int) config('services.ollama.slots', 2));
        $queue = max(0, (int) config('services.ollama.queue', 6));
        $wait = max(0, (int) config('services.ollama.queue_wait', 25));

        $dir = storage_path('framework/llm');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $slot = self::acquireAny($dir, 'slot', $slots);

        if ($slot === null) {
            // Pas de place libre : prendre un ticket dans la file, ou renoncer tout de suite.
            $ticket = self::acquireAny($dir, 'file', $queue);
            if ($ticket === null) {
                Log::notice('IA locale saturée : file pleine, demande refusée.');

                throw new LlmBusyException;
            }

            try {
                $deadline = microtime(true) + $wait;
                while ($slot === null && microtime(true) < $deadline) {
                    usleep(self::POLL_MICROSECONDS);
                    $slot = self::acquireAny($dir, 'slot', $slots);
                }
            } finally {
                self::release($ticket);
            }

            if ($slot === null) {
                Log::notice('IA locale saturée : attente dépassée, demande refusée.', ['attente_s' => $wait]);

                throw new LlmBusyException;
            }
        }

        // L'attente a consommé du temps d'exécution PHP : repartir de zéro pour l'appel lui-même.
        if ((int) ini_get('max_execution_time') !== 0 && function_exists('set_time_limit')) {
            @set_time_limit(max(0, $timeout) + LlmExecutionTime::MARGIN_SECONDS);
        }

        try {
            return $call();
        } finally {
            self::release($slot);
        }
    }

    /** @return resource|null */
    private static function acquireAny(string $dir, string $prefix, int $count)
    {
        for ($i = 0; $i < $count; $i++) {
            $handle = @fopen(sprintf('%s/%s-%d.lock', $dir, $prefix, $i), 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }

        return null;
    }

    /** @param  resource  $handle */
    private static function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
