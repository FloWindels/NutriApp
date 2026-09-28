<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Seule façon de désigner un administrateur.
 *
 * Aucune route, aucune interface ne permet de promouvoir : il faut un accès au serveur. Une
 * variable d'environnement aurait promu en silence et transformé toute fuite de .env en
 * élévation de privilège ; un seeder aurait été rejoué sans trace.
 *
 *   php artisan mavioh:promouvoir florent@example.com
 *   php artisan mavioh:promouvoir florent@example.com --retirer
 */
class PromouvoirAdministrateur extends Command
{
    protected $signature = 'mavioh:promouvoir {email : Adresse du compte} {--retirer : Retirer le rôle au lieu de l’accorder}';

    protected $description = 'Accorde ou retire le rôle d’administrateur à un compte existant';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun compte avec l’adresse {$email}.");

            return self::FAILURE;
        }

        $role = $this->option('retirer') ? UserRole::Utilisateur : UserRole::Administrateur;

        if ($user->role === $role) {
            $this->info("Rien à faire : {$email} est déjà « {$role->label()} ».");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("{$email} est désormais « {$role->label()} ».");

        if ($role === UserRole::Administrateur) {
            $this->line('Rappel : ce compte accède à l’espace d’administration sur /admin.');
        }

        return self::SUCCESS;
    }
}
