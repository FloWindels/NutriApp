<?php

namespace App\Services\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\RequestOptions;
use App\Contracts\LlmWorkoutClient;
use App\Exceptions\LlmUnavailableException;
use App\Services\Sport\WorkoutProposalSchema;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coach sportif IA — implémentation Anthropic (SDK PHP officiel, addendum §C.4).
 *
 * Un appel `messages.create` par génération : prompt système stable (mis en cache, ephemeral),
 * prompt utilisateur = contexte JSON anonymisé, sortie structurée par schéma JSON, sans `thinking`
 * ni préremplissage assistant. Toute erreur (quota, authentification, statut, réseau, refus, JSON
 * illisible) devient une LlmUnavailableException que le générateur attrape pour basculer sur les règles.
 */
class AnthropicWorkoutClient implements LlmWorkoutClient
{
    public const SYSTEM_PROMPT = <<<'TXT'
Tu es le coach sportif de Mavi’oh, prudent et pédagogue. Tu tutoies l’utilisateur.
Tu proposes UNE séance réaliste et adaptée au contexte fourni : objectif, niveau, lieu, matériel disponible, temps, focus, zones douloureuses à ne JAMAIS solliciter, sports pratiqués, historique récent et plan du jour.
Règles :
- Structure obligatoire en trois blocs : « echauffement », « principal », « retour_au_calme ».
- Les durées cumulées (séries × (effort + repos)) doivent correspondre à `duree_min` à ±10 %.
- Utilise en priorité les exercices du catalogue fourni et renvoie leur `exercise_id` ; sinon `exercise_id: null` avec un nom clair et des consignes.
- Respecte strictement le matériel indiqué (« aucun » = poids du corps) et le lieu.
- Pour un sport d’endurance (course, vélo, natation, marche, rameur), propose un bloc principal en fractionné ou en continu avec durées et allures, `exercise_id: null`.
- Débutant : charges légères, technique, pas d’exercice avancé ni de barre lourde.
- Profil mineur, grossesse, allaitement ou suivi médical : intensité douce, pas de HIIT, rappelle l’avis médical dans `warnings`.
- Jamais de promesse médicale, jamais de vocabulaire « brûle-graisse », « détox » ou « garanti ».
- `calories_estimate` prudente (kcal nettes).
- `explication` : 3 à 6 puces en tutoiement qui justifient tes choix à partir du contexte ; `warnings` : les précautions utiles.
- Termine TOUJOURS `explication` par la phrase exacte : « Arrête l’exercice en cas de douleur ou de malaise. Programme indicatif, ne remplace pas un coach ni un avis médical. »
Réponds uniquement avec le JSON demandé, sans texte autour.
TXT;

    public const WEEK_SYSTEM_PROMPT = <<<'TXT'
Tu es le coach sportif de Mavi’oh, prudent et pédagogue. Tu tutoies l’utilisateur.
À partir du contexte fourni (profil, objectif sportif, niveau, temps disponible, lieu, historique récent, sports disponibles), propose une répartition hebdomadaire d’entraînement UNIQUEMENT sur les jours demandés (`jours`, 1 = lundi … 7 = dimanche) : un sport (de préférence parmi `sports_disponibles`), une durée en minutes cohérente avec le temps disponible, un focus court et une note courte par jour.
Alterne intelligemment cardio, renforcement et récupération selon l’objectif ; évite deux séances intenses consécutives ; adapte-toi aux zones douloureuses et à la situation particulière.
Jamais de promesse médicale ni de vocabulaire « brûle-graisse ». `explication` : 2 à 5 puces en tutoiement.
Réponds uniquement avec le JSON demandé, sans texte autour.
TXT;

    public const CLOSING_SENTENCE = 'Réponds uniquement avec le JSON demandé.';

    public function __construct(private ?Client $client = null)
    {
    }

    public function generate(array $context, array $request, array $catalog): array
    {
        return $this->call(self::SYSTEM_PROMPT, $context + ['demande' => $request, 'catalogue' => $catalog], WorkoutProposalSchema::json());
    }

    public function generateWeekPlan(array $context, array $request): array
    {
        return $this->call(self::WEEK_SYSTEM_PROMPT, $context + ['demande' => $request], WorkoutProposalSchema::weekJson());
    }

    /**
     * Décode la réponse du modèle : refus → indisponible ; premier bloc texte = JSON.
     *
     * @return array<string, mixed>
     *
     * @throws LlmUnavailableException
     */
    public function decode(Message $message): array
    {
        if ($message->stopReason === 'refusal') {
            throw new LlmUnavailableException('Le modèle a refusé de répondre à cette demande.');
        }

        foreach ($message->content as $block) {
            if (($block->type ?? null) !== 'text') {
                continue;
            }

            $data = json_decode((string) $block->text, true);
            if (! is_array($data)) {
                throw new LlmUnavailableException('Réponse IA illisible (JSON invalide).');
            }

            return $data;
        }

        throw new LlmUnavailableException('Réponse IA sans contenu texte.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function call(string $systemPrompt, array $payload, array $schema): array
    {
        $userPrompt = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n".self::CLOSING_SENTENCE;

        try {
            $message = $this->client()->messages->create(
                maxTokens: (int) config('services.anthropic.max_tokens', 8000),
                messages: [['role' => 'user', 'content' => $userPrompt]],
                model: (string) config('services.anthropic.model', 'claude-opus-5'),
                outputConfig: [
                    'effort' => (string) config('services.anthropic.effort', 'medium'),
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: [['type' => 'text', 'text' => $systemPrompt, 'cacheControl' => ['type' => 'ephemeral']]],
                requestOptions: RequestOptions::with(
                    timeout: (float) config('services.anthropic.timeout', 90),
                    maxRetries: 1,
                ),
            );
        } catch (RateLimitException $e) {
            $this->fail('quota', $e);
        } catch (AuthenticationException $e) {
            $this->fail('authentification', $e);
        } catch (APIStatusException $e) {
            $this->fail('statut', $e);
        } catch (APIConnectionException $e) {
            $this->fail('reseau', $e);
        } catch (LlmUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail('inconnu', $e);
        }

        return $this->decode($message);
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('services.anthropic.api_key'));
    }

    /**
     * Journalise sans donnée personnelle puis relance en LlmUnavailableException.
     *
     * @throws LlmUnavailableException
     */
    private function fail(string $reason, Throwable $e): never
    {
        Log::warning('Anthropic : génération de séance indisponible.', [
            'raison' => $reason,
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        throw new LlmUnavailableException('Génération IA indisponible ('.$reason.').', $e);
    }
}
