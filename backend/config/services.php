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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    // See CLAUDE.md section 5/8, "Login preko Google-a". Waiting on the owner's real Client
    // ID/Secret, 2026-08-10 — see app/Http/Controllers/Auth/GoogleAuthController.php.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/auth/google/callback'),
    ],

    // Booking.com DACH affiliate program via CJ (Commission Junction), approved 2026-08-21 —
    // see CLAUDE.md section 8. `pid` is CJ's Publisher/Property ID for this specific site
    // ("TripInele — Travel Recommendation Wizard"); `link_id` is a CJ "Evergreen Link" product
    // (deep-linking enabled) confirmed live 2026-08-21 by manually appending a Destination Url
    // in the CJ dashboard's link-builder and clicking through to a real, working Booking.com
    // search-results page. Used by SearchSessionQueryCompiler::wrapWithAffiliateTracking() to
    // wrap the existing public toBookingUrl()/toBookingFlightsUrl() output — falls back to the
    // unwrapped public URL if either is unset (e.g. local/dev), same "never break, just don't
    // track" spirit as the rest of this codebase's optional-integration fallbacks.
    'cj' => [
        'pid' => env('CJ_AFFILIATE_PID'),
        'link_id' => env('CJ_AFFILIATE_LINK_ID'),
    ],

];
