<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Currency & Driver
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('PAYMENT_CURRENCY', 'usd'),

    // 'auto' determines provider based on currency / tenant locale;
    // 'stripe', 'razorpay', or 'fake' forces a specific provider.
    'driver' => env('PAYMENT_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Gateways Configuration
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'stripe' => [
            'name' => 'Stripe',
            'key' => env('STRIPE_KEY', ''),
            'secret' => env('STRIPE_SECRET', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
            'currencies' => ['usd', 'eur', 'gbp', 'cad', 'aud'],
        ],

        'razorpay' => [
            'name' => 'Razorpay',
            'key' => env('RAZORPAY_KEY', ''),
            'secret' => env('RAZORPAY_SECRET', ''),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET', ''),
            'currencies' => ['inr'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale & Currency Routing
    |--------------------------------------------------------------------------
    |
    | Maps tenant countries and currencies to the preferred gateway.
    */
    'routing' => [
        'countries' => [
            'IN' => 'razorpay',
        ],
        'currencies' => [
            'inr' => 'razorpay',
            'usd' => 'stripe',
            'eur' => 'stripe',
            'gbp' => 'stripe',
        ],
        'fallback' => 'stripe',
    ],
];
