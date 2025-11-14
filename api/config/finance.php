<?php

return [
    'multi_currency' => (bool) env('FINANCE_MULTI_CURRENCY', false),
    'default_currency' => env('FINANCE_DEFAULT_CURRENCY', 'USD'),
    'supported_currencies' => [
        'USD',
        'EUR',
        'GBP',
        'CAD',
        'MAD',
    ],
];
