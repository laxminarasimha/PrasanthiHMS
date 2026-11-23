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

    // The outside service that reads hard, handwritten pages when staff ask for it.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // "Sign in with Google": the hospital's own OAuth client ids from Google Cloud (not secret). None set: switched off.
    'google' => [
        'client_ids' => array_filter([env('GOOGLE_WEB_CLIENT_ID'), env('GOOGLE_IOS_CLIENT_ID')]),
        'web_client_id' => env('GOOGLE_WEB_CLIENT_ID'),
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

];
