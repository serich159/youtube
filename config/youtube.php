<?php

return [

    /**
     * Client ID.
     */
    'client_id' => env('GOOGLE_CLIENT_ID', null),

    /**
     * Client Secret.
     */
    'client_secret' => env('GOOGLE_CLIENT_SECRET', null),

    /**
     * Scopes.
     */
    'scopes' => [
        'https://www.googleapis.com/auth/youtube',
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly'
    ],

    /**
     * Route URI's
     */
    'routes' => [

        /**
         * Determine if the Routes should be disabled.
         * Always leave this on for multiple users.
         */
        'enabled' => true,

        /**
         * The prefix for the below URI's
         */
        'prefix' => 'youtube',

        /**
         * Redirect URI
         */
        'redirect_uri' => 'callback',

        /**
         * The autentication URI
         */
        'authentication_uri' => 'auth',

        /**
         * The redirect back URI
         */
        'redirect_back_uri' => '/',

        /**
         * The redirect back URI, when youtube auth failed.
         */
        'redirect_failed_uri' => '/',

    ]

];
