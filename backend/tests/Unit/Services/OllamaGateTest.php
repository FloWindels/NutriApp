<?php

namespace Tests\Unit\Services;

use App\Exceptions\LlmBusyException;
use App\Exceptions\LlmUnavailableException;
use App\Services\Llm\OllamaGate;
use Tests\TestCase;

/**
 * File d'attente du modèle local : une place libre laisse passer, une file pleine refuse tout
 * de suite, une attente trop longue refuse au délai, et une place se libère même sur exception.
 * Les places occupées sont simulées par des verrous pris dans ce même processus : flock()
 * s'applique par descripteur ouvert, un second fopen() bute donc dessus.
 */
class OllamaGateTest extends TestCase
{
    /** @var list<resource> */
    private array $tenus = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ollama.slots' => 1,
            'services.ollama.queue' => 1,
            'services.ollama.queue_wait' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tenus as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->tenus = [];
        parent::tearDown();
    }

    private function occuper(string $nom): void
    {
        $dir = storage_path('framework/llm');
        @mkdir($dir, 0775, true);
        $handle = fopen($dir.'/'.$nom.'.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'Verrou de test déjà pris : '.$nom);
        $this->tenus[] = $handle;
    }

    public function test_une_place_libre_laisse_passer_l_appel(): void
    {
        $this->assertSame('ok', OllamaGate::run(10, fn () => 'ok'));
    }

    public function test_la_place_est_rendue_meme_si_l_appel_echoue(): void
    {
        try {
            OllamaGate::run(10, fn () => throw new \RuntimeException('panne'));
        } catch (\RuntimeException) {
        }

        $this->assertSame('ok', OllamaGate::run(10, fn () => 'ok'));
    }

    public function test_file_pleine_refuse_sans_attendre(): void
    {
        $this->occuper('slot-0');
        $this->occuper('file-0');

        $debut = microtime(true);
        try {
            OllamaGate::run(10, fn () => 'jamais');
            $this->fail('La demande aurait dû être refusée.');
        } catch (LlmBusyException $e) {
            $this->assertInstanceOf(LlmUnavailableException::class, $e, 'Les replis existants doivent s’appliquer.');
            $this->assertSame(LlmBusyException::MESSAGE, $e->getMessage());
        }
        $this->assertLessThan(0.5, microtime(true) - $debut);
    }

    public function test_attente_depassee_refuse_au_delai(): void
    {
        $this->occuper('slot-0');

        $debut = microtime(true);
        try {
            OllamaGate::run(10, fn () => 'jamais');
            $this->fail('La demande aurait dû être refusée.');
        } catch (LlmBusyException) {
        }
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $debut);
    }

    public function test_un_refus_rend_un_503_avec_retry_after(): void
    {
        $reponse = (new LlmBusyException(30))->render();

        $this->assertSame(503, $reponse->getStatusCode());
        $this->assertSame('30', $reponse->headers->get('Retry-After'));
    }
}
