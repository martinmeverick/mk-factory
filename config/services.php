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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | ARES — veřejný registr ekonomických subjektů (MF ČR). Bez klíče
    | a registrace. Slouží jen k předvyplnění údajů; aplikace na něm
    | nesmí být závislá (viz docs/ARES_INTEGRATION.md).
    */
    'ares' => [
        'enabled' => (bool) env('ARES_ENABLED', true),
        'base_uri' => env('ARES_BASE_URI', 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest'),
        'timeout' => (int) env('ARES_TIMEOUT', 5),
        'connect_timeout' => (int) env('ARES_CONNECT_TIMEOUT', 3),
        // Registr se mění zřídka; cache výrazně snižuje počet dotazů.
        'cache_ttl' => (int) env('ARES_CACHE_TTL', 86400),
        'missing_cache_ttl' => (int) env('ARES_MISSING_CACHE_TTL', 900),
    ],

];
