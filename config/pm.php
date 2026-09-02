<?php

declare(strict_types=1);

return [

    'jira' => [
        'client_id' => env('JIRA_CLIENT_ID'),
        'client_secret' => env('JIRA_CLIENT_SECRET'),
        'redirect_uri' => env('JIRA_REDIRECT_URI'),
        'scopes' => 'read:jira-work write:jira-work offline_access',
    ],

    'asana' => [
        'auth_type' => 'api_key',
    ],

    'linear' => [
        'auth_type' => 'api_key',
    ],

    'monday' => [
        'client_id' => env('MONDAY_CLIENT_ID'),
        'client_secret' => env('MONDAY_CLIENT_SECRET'),
        'redirect_uri' => env('MONDAY_REDIRECT_URI'),
    ],

    'sync' => [
        'enabled' => env('PM_SYNC_ENABLED', true),
        'auto_push_on_create' => env('PM_AUTO_PUSH_ON_CREATE', true),
    ],

    'queue' => env('PM_QUEUE', 'pm-sync'),

];
