<?php

declare(strict_types=1);

return [
    /*
     * Regional pricing: Ghana visitors see GHS, everyone else fixed USD prices
     * charged in GHS at the Bank of Ghana interbank rate.
     */
    'fx' => [
        'cache_minutes' => (int) env('FX_CACHE_MINUTES', 90),
        'alert_after_days' => (int) env('FX_ALERT_AFTER_DAYS', 4),
        'pause_after_days' => (int) env('FX_PAUSE_AFTER_DAYS', 10),
        'bog_url' => env('FX_BOG_URL', 'https://www.bog.gov.gh/treasury-and-the-markets/daily-interbank-fx-rates/'),
        'republisher_url' => env('FX_REPUBLISHER_URL', ''),
        'republisher_key' => env('FX_REPUBLISHER_KEY', ''),
    ],
];
