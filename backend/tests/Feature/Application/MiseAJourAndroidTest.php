<?php

namespace Tests\Feature\Application;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MiseAJourAndroidTest extends TestCase
{
    use RefreshDatabase;

    private function apkFactice(string $contenu = 'APK factice'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'apk').'.apk';
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    public function test_rien_sans_compte(): void
    {
        Storage::fake('local');

        $this->getJson('/api/app/android')->assertUnauthorized();
        $this->getJson('/api/app/android/apk')->assertUnauthorized();
    }

    public function test_sans_version_publiee_on_repond_404(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/app/android')->assertNotFound();
        $this->getJson('/api/app/android/apk')->assertNotFound();
    }

    public function test_publier_puis_lire_et_telecharger(): void
    {
        Storage::fake('local');
        $apk = $this->apkFactice();

        $this->artisan('mavioh:publier-apk', [
            'fichier' => $apk,
            '--code' => 3,
            '--nom' => '1.2.0',
            '--notes' => 'Ajout de la mise à jour intégrée.',
        ])->assertSuccessful();

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/app/android')
            ->assertOk()
            ->assertJsonPath('data.version_code', 3)
            ->assertJsonPath('data.version_name', '1.2.0')
            ->assertJsonPath('data.notes', 'Ajout de la mise à jour intégrée.')
            ->assertJsonPath('data.sha256', hash('sha256', 'APK factice'))
            ->assertJsonMissingPath('data.fichier');

        $reponse = $this->get('/api/app/android/apk');
        $reponse->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $reponse->headers->get('Content-Type'));
    }

    public function test_un_code_qui_ne_monte_pas_est_refuse_et_l_ancien_apk_retire(): void
    {
        Storage::fake('local');

        $this->artisan('mavioh:publier-apk', ['fichier' => $this->apkFactice('v3'), '--code' => 3, '--nom' => '1.2.0'])->assertSuccessful();
        $this->artisan('mavioh:publier-apk', ['fichier' => $this->apkFactice('v3 bis'), '--code' => 3, '--nom' => '1.2.1'])->assertFailed();
        $this->artisan('mavioh:publier-apk', ['fichier' => $this->apkFactice('v4'), '--code' => 4, '--nom' => '1.3.0'])->assertSuccessful();

        Storage::disk('local')->assertMissing('android/mavioh-3.apk');
        Storage::disk('local')->assertExists('android/mavioh-4.apk');
    }
}
