<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile Settings
    |--------------------------------------------------------------------------
    |
    | Used to verify human presence when anonymous send volume exceeds
    | the burst challenge threshold from a single IP.
    |
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Probe Workspace
    |--------------------------------------------------------------------------
    |
    | Which organisation and which attendee ProbeWorkspaceSeeder furnishes, so
    | the whole workspace can be looked at with every part switched on.
    |
    */
    'probe' => [
        'tenant_slug' => env('PROBE_TENANT_SLUG', 'miconvener-probe'),
        'attendee_email' => env('PROBE_ATTENDEE_EMAIL', 'hiselase@gmail.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Abuse Control Limits
    |--------------------------------------------------------------------------
    |
    | Limits protecting mailbox delivery and preventing enumeration/spraying.
    |
    */
    'rate_limits' => [
        'email_cooldown_seconds' => (int) env('ATTENDEE_PORTAL_EMAIL_COOLDOWN_SECONDS', 60),
        'email_hourly' => (int) env('ATTENDEE_PORTAL_EMAIL_HOURLY', 5),
        'email_daily' => (int) env('ATTENDEE_PORTAL_EMAIL_DAILY', 10),
        'ip_burst' => (int) env('ATTENDEE_PORTAL_IP_BURST', 20),
        'ip_burst_window_seconds' => (int) env('ATTENDEE_PORTAL_IP_BURST_WINDOW_SECONDS', 600),
        'ip_challenge_threshold' => (int) env('ATTENDEE_PORTAL_IP_CHALLENGE_THRESHOLD', 5),
        // A backstop, not the control: the challenge above does that work.
        // Sized for a venue where a thousand delegates share one address.
        'ip_daily' => (int) env('ATTENDEE_PORTAL_IP_DAILY', 2000),
        'confirm_email_limit' => (int) env('ATTENDEE_PORTAL_CONFIRM_EMAIL_LIMIT', 10),
        'confirm_email_window_seconds' => (int) env('ATTENDEE_PORTAL_CONFIRM_EMAIL_WINDOW_SECONDS', 600),
        // Guessing is already capped where it can do harm: five tries per
        // code, ten per address. This only has to stop a flood, and a hall
        // entering codes at once is not one.
        'confirm_ip_limit' => (int) env('ATTENDEE_PORTAL_CONFIRM_IP_LIMIT', 600),
        'confirm_ip_window_seconds' => (int) env('ATTENDEE_PORTAL_CONFIRM_IP_WINDOW_SECONDS', 600),
    ],
];
