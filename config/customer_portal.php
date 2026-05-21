<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Customer Portal Access
    |--------------------------------------------------------------------------
    |
    | These settings apply only to customer-facing portal links. They do not
    | control staff, reception, manager, or owner login sessions.
    |
    */

    'token_lifetime_days' => (int) env('CUSTOMER_PORTAL_TOKEN_LIFETIME_DAYS', 60),

    'idle_timeout_minutes' => (int) env('CUSTOMER_PORTAL_IDLE_TIMEOUT_MINUTES', 60),
];
