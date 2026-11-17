<?php

return [
    'brand' => env('PUBLIC_BOOKING_BRAND', 'VINA'),
    'currency' => 'AED',
    'nomod_key' => env('NOMOD_HOSTED_CHECKOUT_API_KEY'),
    'nomod_url' => 'https://api.nomod.com/v1',
    'reschedule_days' => 14,
    'approval_cutoff_hours' => 24,
];
