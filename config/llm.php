<?php

declare(strict_types=1);

return [
    'models' => [
        'gpt-4o' => [
            'input_price_per_1k' => 0.005,
            'output_price_per_1k' => 0.015,
        ],
        'gpt-4-turbo' => [
            'input_price_per_1k' => 0.01,
            'output_price_per_1k' => 0.03,
        ],
        'gpt-3.5-turbo' => [
            'input_price_per_1k' => 0.0005,
            'output_price_per_1k' => 0.0015,
        ],
        'claude-3-5-sonnet' => [
            'input_price_per_1k' => 0.003,
            'output_price_per_1k' => 0.015,
        ],
        'claude-3-opus' => [
            'input_price_per_1k' => 0.015,
            'output_price_per_1k' => 0.075,
        ],
        'claude-3-haiku' => [
            'input_price_per_1k' => 0.00025,
            'output_price_per_1k' => 0.00125,
        ],
        'anthropic.claude-3-haiku-20240307-v1:0' => [
            'input_price_per_1k' => 0.00025,
            'output_price_per_1k' => 0.00125,
        ],
        'anthropic.claude-3-5-sonnet-20241022-v2:0' => [
            'input_price_per_1k' => 0.003,
            'output_price_per_1k' => 0.015,
        ],
    ],
    'default_model' => 'gpt-3.5-turbo',
    'global_spending_limit' => env('LLM_GLOBAL_SPENDING_LIMIT', 500.00), // USD
    'alert_email' => env('LLM_ALERT_EMAIL', 'admin@example.com'),

    /*
    |--------------------------------------------------------------------------
    | Token Packs
    |--------------------------------------------------------------------------
    |
    | Define one-time token purchase options for tenants.
    |
    */
    'token_packs' => [
        'starter' => [
            'name' => 'Starter Pack',
            'tokens' => 500000,
            'price' => 5.00,
            'currency' => 'USD',
        ],
        'standard' => [
            'name' => 'Standard Pack',
            'tokens' => 2000000,
            'price' => 15.00,
            'currency' => 'USD',
        ],
        'enterprise' => [
            'name' => 'Enterprise Pack',
            'tokens' => 10000000,
            'price' => 50.00,
            'currency' => 'USD',
        ],
    ],
];
