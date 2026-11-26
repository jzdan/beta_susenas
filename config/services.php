<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Login SSO Bangdata (OAuth2, server Laravel Passport di sso.bangdata.web.id). Nonaktif selama
    // client id belum diisi. Nama env BANGDATA_* utama; SSO_* (gaya contoh panel SSO) diterima sebagai cadangan.
    'bangdata' => [
        'url' => rtrim((string) env('BANGDATA_SSO_URL', env('SSO_BASE_URL', 'https://sso.bangdata.web.id')), '/'),
        'client_id' => env('BANGDATA_CLIENT_ID', env('SSO_CLIENT_ID')),
        'client_secret' => env('BANGDATA_CLIENT_SECRET', env('SSO_CLIENT_SECRET')),
        'redirect' => env('BANGDATA_REDIRECT_URI', env('SSO_REDIRECT_URI')),
        'scope' => env('BANGDATA_SCOPE', ''),
    ],

];
