<?php

declare(strict_types = 1);

return [
    'min_customers' => env('BTYD_MIN_CUSTOMERS', 10),

    'horizon_months' => env('BTYD_HORIZON_MONTHS', 12),

    'discount_rate' => env('BTYD_DISCOUNT_RATE', 0.1),

    'bgnbd_initial' => [
        'r'     => 0.5,
        'alpha' => 1.0,
        'a'     => 1.0,
        'b'     => 1.0,
    ],

    'gamma_gamma_initial' => [
        'p' => 1.0,
        'q' => 1.0,
        'v' => 1.0,
    ],

    'optimizer' => [
        'max_iter' => 2000,
        'tol'      => 1e-6,
    ],
];
