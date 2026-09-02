<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Global Markup Percentage
    |--------------------------------------------------------------------------
    |
    | This percentage is added to all metered costs by default.
    |
    */
    'global_markup' => (float) env('BILLING_GLOBAL_MARKUP', 0),
];
