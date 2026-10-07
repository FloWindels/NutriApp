<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** La page d'accueil Laravel par défaut n'est plus servie : le backend n'expose que l'API. */
    public function test_the_root_url_is_not_served(): void
    {
        $this->get('/')->assertNotFound();
    }

    /** Pas d'authentification SPA par cookie : la route CSRF de Sanctum n'existe pas. */
    public function test_sanctum_csrf_cookie_route_is_disabled(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }
}
