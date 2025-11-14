<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inventory settings
    |--------------------------------------------------------------------------
    |
    | Control how stock adjustments behave. By default we do not allow stock
    | levels to drop below zero to keep inventory accurate. Set the
    | INVENTORY_ALLOW_NEGATIVE_STOCK environment variable to true if you
    | need to permit negative balances.
    |
    */

    'allow_negative_stock' => env('INVENTORY_ALLOW_NEGATIVE_STOCK', false),

    /*
    |--------------------------------------------------------------------------
    | Track stock guard
    |--------------------------------------------------------------------------
    |
    | When false we skip mutating stock levels for variants or products that
    | have stock tracking disabled. Set the environment flag to true if you
    | need to allow adjustments in spite of tracking being off (a warning will
    | be logged when this happens).
    |
    */

    'allow_adjust_when_tracking_disabled' => env('INVENTORY_ALLOW_ADJUST_WHEN_TRACK_DISABLED', false),
];
