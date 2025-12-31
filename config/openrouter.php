<?php

declare(strict_types=1);

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'model' => env('OPENROUTER_MODEL', 'qwen/qwen-2.5-vl-7b-instruct:free'),
    'base_url' => 'https://openrouter.ai/api/v1',
];
