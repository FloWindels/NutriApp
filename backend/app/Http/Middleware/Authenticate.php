<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * L'API est purement JSON : aucune redirection, on renvoie toujours 401.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
