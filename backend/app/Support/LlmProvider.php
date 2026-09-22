<?php

namespace App\Support;

/**
 * Choix du fournisseur de LLM pour le coach sportif.
 *
 * `LLM_PROVIDER` vaut `auto` (défaut), `anthropic`, `ollama` ou `none`.
 * En mode automatique, Anthropic l'emporte quand une clé est configurée, sinon Ollama s'il est
 * activé, sinon aucun : la génération bascule alors sur les règles Mavi'oh.
 */
class LlmProvider
{
    public const ANTHROPIC = 'anthropic';

    public const OLLAMA = 'ollama';

    public const NONE = 'none';

    /** Fournisseur effectivement utilisable. */
    public static function current(): string
    {
        $configured = (string) config('services.llm.provider', 'auto');

        return match ($configured) {
            self::ANTHROPIC => self::anthropicReady() ? self::ANTHROPIC : self::NONE,
            self::OLLAMA => self::ollamaReady() ? self::OLLAMA : self::NONE,
            self::NONE => self::NONE,
            default => match (true) {
                self::anthropicReady() => self::ANTHROPIC,
                self::ollamaReady() => self::OLLAMA,
                default => self::NONE,
            },
        };
    }

    public static function isConfigured(): bool
    {
        return self::current() !== self::NONE;
    }

    /** Nom du modèle à afficher dans l'interface, null quand l'IA est indisponible. */
    public static function modelName(): ?string
    {
        return match (self::current()) {
            self::ANTHROPIC => (string) config('services.anthropic.model'),
            self::OLLAMA => (string) config('services.ollama.model'),
            default => null,
        };
    }

    private static function anthropicReady(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    private static function ollamaReady(): bool
    {
        return (bool) config('services.ollama.enabled')
            && filled(config('services.ollama.base_url'))
            && filled(config('services.ollama.model'));
    }
}
