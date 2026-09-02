<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Secrets Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default secrets provider used by your application.
    |
    | Supported: "local", "aws"
    |
    */

    'default' => env('SECRETS_PROVIDER', 'local'),

    'providers' => [

        'local' => [
            'path' => storage_path('secrets.json'),
        ],

        'aws' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'kms_key_id' => env('AWS_KMS_KEY_ID'),
        ],

    ],

];
