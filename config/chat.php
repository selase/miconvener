<?php

declare(strict_types=1);

return [

    'slack' => [
        'client_id' => env('SLACK_CLIENT_ID'),
        'client_secret' => env('SLACK_CLIENT_SECRET'),
        'redirect_uri' => env('SLACK_REDIRECT_URI'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'scopes' => 'chat:write,channels:read,users:read,commands,im:write',
    ],

    'teams' => [
        'client_id' => env('TEAMS_CLIENT_ID'),
        'client_secret' => env('TEAMS_CLIENT_SECRET'),
        'redirect_uri' => env('TEAMS_REDIRECT_URI'),
        'tenant_id' => env('TEAMS_TENANT_ID'),
    ],

    'notifications' => [
        'minutes_summary' => env('CHAT_NOTIFY_MINUTES_SUMMARY', true),
        'task_assignment' => env('CHAT_NOTIFY_TASK_ASSIGNMENT', true),
        'meeting_reminder' => env('CHAT_NOTIFY_MEETING_REMINDER', true),
        'task_status_update' => env('CHAT_NOTIFY_TASK_STATUS_UPDATE', true),
    ],

    'queue' => env('CHAT_QUEUE', 'chat-notifications'),

];
