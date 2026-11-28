<?php

namespace App\Services\Checkout;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpCoreCheckoutAuthority implements CoreCheckoutAuthority
{
    public function start(string $attemptId, int $amountMinor, string $currency): array
    {
        return $this->send('start', ['attempt_id' => $attemptId, 'amount_minor' => $amountMinor, 'currency' => $currency]);
    }

    public function readback(string $attemptId): array
    {
        return $this->send('readback', ['attempt_id' => $attemptId]);
    }

    private function send(string $operation, array $data): array
    {
        $base = config('marketplace-checkout.core_url');
        $secret = config('marketplace-checkout.transport_secret');
        $unavailable = ['status' => 'unavailable'];
        if (! app()->environment('staging', 'testing')
            || config('marketplace-checkout.enabled') !== true
            || ! is_string($secret) || strlen($secret) < 32
            || ! is_string($base) || ! preg_match('~\Ahttps://[a-zA-Z0-9.-]+(?::443)?/?\z~D', $base)) {
            return $unavailable;
        }

        try {
            $response = Http::acceptJson()->asJson()->withToken($secret)
                ->connectTimeout(4)->timeout(18)->withOptions(['allow_redirects' => false])
                ->post(rtrim($base, '/').'/api/internal/marketplace/collection/'.$operation, $data);
        } catch (ConnectionException) {
            return $unavailable;
        }

        $result = $response->successful() ? $response->json() : null;

        return is_array($result) ? $result : $unavailable;
    }
}
