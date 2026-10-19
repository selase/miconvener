<?php

declare(strict_types=1);

return [
    /*
     * Staff room booking (Enterprise): the limits an organisation's staff
     * work within unless the organisation sets its own (owner, 2026-10-19:
     * agreed as defaults the organisation can change).
     */
    'staff' => [
        'defaults' => [
            'max_hours' => 8,
            'window_days' => 90,
            'open_requests' => 5,
        ],
    ],
];
