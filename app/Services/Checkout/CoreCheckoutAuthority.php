<?php

namespace App\Services\Checkout;

/**
 * Trusted Core service boundary. A future transport must authenticate Core and
 * must never substitute browser state, callback bodies, or legacy paid orders.
 * Deliberately unbound until a real sandbox transport has been reviewed.
 */
interface CoreCheckoutAuthority
{
    public function start(string $attemptId, int $amountMinor, string $currency): array;

    public function readback(string $attemptId): array;
}
