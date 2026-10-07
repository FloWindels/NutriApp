<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\AdminJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Redéfinit le mot de passe d'un compte depuis le serveur, quand le courriel de
 * réinitialisation ne peut pas partir.
 *
 * Le mot de passe se saisit au clavier, masqué : il n'apparaît ni dans la ligne de commande,
 * ni dans l'historique du shell, ni dans les journaux. Toutes les sessions du compte sont
 * fermées, et l'opération est consignée au journal d'administration.
 *
 *   php artisan mavioh:mot-de-passe florent@example.com
 */
class RedefinirMotDePasse extends Command
{
    protected $signature = 'mavioh:mot-de-passe {email : Adresse du compte}';

    protected $description = 'Redéfinit le mot de passe d’un compte (saisie masquée) et ferme ses sessions';

    public function handle(AdminJournal $journal): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun compte avec l’adresse {$email}.");

            return self::FAILURE;
        }

        $motDePasse = (string) $this->secret('Nouveau mot de passe (10 caractères au moins)');
        $confirmation = (string) $this->secret('Confirme le mot de passe');

        $validation = Validator::make(
            ['password' => $motDePasse, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::min(10)]],
            [],
            ['password' => 'mot de passe'],
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $erreur) {
                $this->error($erreur);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $motDePasse): void {
            $user->forceFill(['password' => Hash::make($motDePasse)])->save();
            // Un mot de passe redéfini ferme toutes les sessions, comme une réinitialisation.
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        });

        $journal->enregistrerSysteme(
            'redefinir_mot_de_passe',
            'user',
            $user->id,
            $user->email,
            'Mot de passe redéfini en ligne de commande (mavioh:mot-de-passe).',
        );

        $this->info("Mot de passe de {$email} redéfini. Toutes ses sessions ont été fermées.");

        return self::SUCCESS;
    }
}
