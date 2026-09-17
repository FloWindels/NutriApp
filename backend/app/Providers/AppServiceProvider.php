<?php

namespace App\Providers;

use App\Contracts\LlmWorkoutClient;
use App\Services\Llm\AnthropicWorkoutClient;
use App\Services\Llm\NullWorkoutClient;
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
            return filled(config('services.anthropic.api_key'))
                ? $app->make(AnthropicWorkoutClient::class)
                : $app->make(NullWorkoutClient::class);
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
