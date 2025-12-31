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

];
