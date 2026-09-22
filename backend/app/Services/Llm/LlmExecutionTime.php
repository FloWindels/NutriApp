<?php

namespace App\Services\Llm;

/**
 * Relève la limite d'exécution PHP le temps d'un appel au coach IA.
 *
 * Un modèle local ou un modèle distant chargé met couramment plus de 30 s à répondre, soit la
 * valeur par défaut de `max_execution_time` : sans ce relèvement, la requête meurt en erreur
 * fatale avant même que le délai HTTP ne soit atteint, et le repli sur les règles Mavi'oh n'a
 * jamais lieu. Sans effet en ligne de commande, où la limite est déjà illimitée.
 */
class LlmExecutionTime
{
    /** Marge ajoutée au délai HTTP pour laisser le temps de traiter la réponse. */
    public const MARGIN_SECONDS = 30;

    public static function allow(int $timeoutSeconds): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        $current = (int) ini_get('max_execution_time');
        $needed = max(0, $timeoutSeconds) + self::MARGIN_SECONDS;

        // 0 signifie « sans limite » (cas de la CLI) : ne rien réduire.
        if ($current === 0 || $current >= $needed) {
            return;
        }

        @set_time_limit($needed);
    }
}
