<?php

return [
    'enabled' => env('MARKETPLACE_ENABLE_STAGING_CHECKOUT', false),
    'core_url' => env('MARKETPLACE_STAGING_CORE_URL'),
    'transport_secret' => env('MARKETPLACE_STAGING_TRANSPORT_SECRET'),
    'bootcamps_seller_user_id' => null,
    // Explicit whole XAF per Marketplace product ID, never a legacy USD price or silent conversion.
    'xaf_prices' => [],
];
