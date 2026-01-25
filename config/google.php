<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Places API
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Places API used to fetch business reviews
    | and place details.
    |
    */

    'places' => [
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
        'base_url' => 'https://maps.googleapis.com/maps/api/place',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Play Store API
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Play Developer API used to fetch app reviews
    | and reply to them.
    |
    */

    'play_store' => [
        'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
        'service_account_json' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google My Business API
    |--------------------------------------------------------------------------
    |
    | Configuration for Google My Business (Business Profile) API OAuth
    | used to manage business listings and locations.
    |
    */

    'my_business' => [
        'client_id' => env('GOOGLE_GMP_CLIENT_ID'),
        'client_secret' => env('GOOGLE_GMP_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_GMP_REDIRECT_URI'),
        'scopes' => [
            'https://www.googleapis.com/auth/business.manage',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

];
