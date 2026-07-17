<?php

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'database' => [
            'driver' => 'database',
            'table' => 'cache',
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'discount_hub_cache'),
];
