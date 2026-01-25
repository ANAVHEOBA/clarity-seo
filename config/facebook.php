<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Facebook App Credentials
    |--------------------------------------------------------------------------
    */
    'app_id' => env('FACEBOOK_APP_ID'),
    'app_secret' => env('FACEBOOK_APP_SECRET'),
    'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v24.0'),

    /*
    |--------------------------------------------------------------------------
    | Facebook Graph API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => 'https://graph.facebook.com',

    /*
    |--------------------------------------------------------------------------
    | Default Permissions for Page Management
    |--------------------------------------------------------------------------
    */
    'default_permissions' => [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_metadata',
        'pages_manage_posts',
        'pages_manage_engagement',  // Required for responding to reviews
        'pages_read_user_content',  // Required for reading reviews
        'business_management',
        'instagram_basic',          // Required for reading Instagram profile info
        'instagram_manage_comments', // Required for reading/replying to Instagram comments
    ],

];
