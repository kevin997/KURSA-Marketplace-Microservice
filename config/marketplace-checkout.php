<?php

$seller = filter_var(env('MARKETPLACE_STAGING_BOOTCAMPS_SELLER_USER_ID'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$encodedPrices = env('MARKETPLACE_STAGING_XAF_PRICES');
$configuredPrices = is_string($encodedPrices) ? json_decode($encodedPrices, depth: 4) : null;
$prices = [];

// Require a JSON object of canonical product IDs to whole FCFA values.
// One malformed entry disables the mapping so a typo cannot undercharge a SKU.
if ($configuredPrices instanceof stdClass) {
    foreach ($configuredPrices as $id => $price) {
        $productId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || (string) $productId !== (string) $id
            || ! is_int($price) || $price < 1 || $price > 10_000_000) {
            $prices = [];
            break;
        }
        $prices[$productId] = $price;
    }
}

return [
    'enabled' => env('MARKETPLACE_ENABLE_STAGING_CHECKOUT', false),
    'core_url' => env('MARKETPLACE_STAGING_CORE_URL'),
    'transport_secret' => env('MARKETPLACE_STAGING_TRANSPORT_SECRET'),
    'bootcamps_seller_user_id' => $seller === false ? null : $seller,
    // Explicit whole XAF per Marketplace product ID, never a legacy USD price or silent conversion.
    'xaf_prices' => $prices,
];
