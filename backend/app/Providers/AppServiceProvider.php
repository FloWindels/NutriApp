<?php

namespace App\Providers;

use App\Contracts\WebSearchClient;
use App\Contracts\LlmPlannerClient;
use App\Contracts\LlmRecipeClient;
use App\Contracts\LlmVisionClient;
use App\Contracts\LlmWorkoutClient;
use App\Services\Llm\AnthropicPlannerClient;
use App\Services\Llm\AnthropicRecipeClient;
use App\Services\Llm\AnthropicVisionClient;
use App\Services\Llm\AnthropicWorkoutClient;
use App\Services\Llm\NullPlannerClient;
use App\Services\Llm\NullRecipeClient;
use App\Services\Llm\NullVisionClient;
use App\Services\Llm\NullWorkoutClient;
use App\Services\Llm\OllamaPlannerClient;
use App\Services\Llm\OllamaRecipeClient;
use App\Services\Llm\OllamaVisionClient;
use App\Services\Llm\OllamaWorkoutClient;
use App\Services\Search\NullWebSearchClient;
use App\Services\Search\SearxWebSearchClient;
use App\Support\LlmProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Coach IA : client Anthropic si une clé est configurée, sinon client nul
        // (LlmUnavailableException → repli sur les règles Mavi'oh). Résolu à la demande
        // pour que les tests puissent surcharger la config avant la première résolution.
        $this->app->bind(LlmWorkoutClient::class, function ($app) {
            return match (LlmProvider::current()) {
                LlmProvider::ANTHROPIC => $app->make(AnthropicWorkoutClient::class),
                LlmProvider::OLLAMA => $app->make(OllamaWorkoutClient::class),
                default => $app->make(NullWorkoutClient::class),
            };
        });

        // Recherche web : désactivée tant qu'aucune instance n'est configurée.
        $this->app->bind(WebSearchClient::class, function ($app) {
            return filled(config('services.recherche.base_url'))
                ? $app->make(SearxWebSearchClient::class)
                : $app->make(NullWebSearchClient::class);
        });

        // Rédiger une recette est de la génération de texte : même sélecteur que le coach.
        $this->app->bind(LlmRecipeClient::class, function ($app) {
            return match (LlmProvider::current()) {
                LlmProvider::ANTHROPIC => $app->make(AnthropicRecipeClient::class),
                LlmProvider::OLLAMA => $app->make(OllamaRecipeClient::class),
                default => $app->make(NullRecipeClient::class),
            };
        });

        // Organiser la semaine de repas : même sélecteur encore, le modèle ne fait que choisir
        // parmi des recettes existantes.
        $this->app->bind(LlmPlannerClient::class, function ($app) {
            return match (LlmProvider::current()) {
                LlmProvider::ANTHROPIC => $app->make(AnthropicPlannerClient::class),
                LlmProvider::OLLAMA => $app->make(OllamaPlannerClient::class),
                default => $app->make(NullPlannerClient::class),
            };
        });

        // La vision a ses propres prérequis : un modèle multimodal, pas seulement un LLM.
        $this->app->bind(LlmVisionClient::class, function ($app) {
            return match (LlmProvider::visionCurrent()) {
                LlmProvider::ANTHROPIC => $app->make(AnthropicVisionClient::class),
                LlmProvider::OLLAMA => $app->make(OllamaVisionClient::class),
                default => $app->make(NullVisionClient::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Le lazy loading est interdit hors production : chaque N+1 lève une exception en dev/tests.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
