<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Open Food Facts (données nutritionnelles, licence ODbL)
    |--------------------------------------------------------------------------
    |
    | Les tests fixent OFF_BASE_URL=https://off.test et utilisent Http::fake().
    |
    */

    'off' => [
        'base_url' => env('OFF_BASE_URL', 'https://world.openfoodfacts.org'),
        'user_agent' => env('OFF_USER_AGENT', 'Mavioh/1.0 (contact@mavioh.app)'),
        'timeout' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (coach sportif IA — optionnel)
    |--------------------------------------------------------------------------
    |
    | Sans ANTHROPIC_API_KEY, la génération IA est désactivée et les séances
    | sont proposées par les règles Mavi'oh (AppServiceProvider lie alors
    | LlmWorkoutClient à NullWorkoutClient).
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8000),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 90),
    ],

];
