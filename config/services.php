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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
        'regions' => env('ODDS_API_REGIONS', 'eu'),
        'markets' => env('ODDS_API_MARKETS', 'h2h,totals'),
    ],

    'football_data' => [
        'key' => env('FOOTBALL_DATA_API_KEY'),
    ],

    'api_football' => [
        'key' => env('API_FOOTBALL_KEY'),
    ],

    'sportmonks' => [
        'token' => env('SPORTMONKS_API_TOKEN'),
        'base_url' => env('SPORTMONKS_BASE_URL', 'https://api.sportmonks.com/v3'),
        'store_payloads' => env('SPORTMONKS_STORE_PAYLOADS', true),
    ],

    'open_meteo' => [
        'forecast_url' => env('OPEN_METEO_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'archive_url' => env('OPEN_METEO_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
        'store_payloads' => env('OPEN_METEO_STORE_PAYLOADS', true),
    ],

    'betlens' => [
        'admin_password_hash' => env('BETLENS_ADMIN_PASSWORD_HASH'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
