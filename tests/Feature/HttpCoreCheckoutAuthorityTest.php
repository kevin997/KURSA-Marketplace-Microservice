<?php

namespace Tests\Feature;

use App\Services\Checkout\HttpCoreCheckoutAuthority;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpCoreCheckoutAuthorityTest extends TestCase
{
    public function test_sends_only_order_facts_to_configured_https_core(): void
    {
        $secret = str_repeat('t', 36);
        config([
            'marketplace-checkout.enabled' => true,
            'marketplace-checkout.core_url' => 'https://core.sandbox.test',
            'marketplace-checkout.transport_secret' => $secret,
        ]);
        $id = 'b03052ea-3d51-401f-8eca-6cb440417971';
        $url = 'https://core.sandbox.test/api/internal/marketplace/collection/start';
        Http::preventStrayRequests()->fake([$url => Http::response(['status' => 'pending', 'attempt_id' => $id, 'amount_minor' => 500, 'currency' => 'XAF'])]);
        $result = (new HttpCoreCheckoutAuthority)->start($id, 500, 'XAF');
        $this->assertSame('pending', $result['status']);
        Http::assertSent(fn (Request $r) => $r->url() === $url
            && $r->hasHeader('Authorization', 'Bearer '.$secret)
            && $r->data() === ['attempt_id' => $id, 'amount_minor' => 500, 'currency' => 'XAF']);
    }

    public function test_fails_closed_without_a_safe_origin(): void
    {
        config([
            'marketplace-checkout.enabled' => true,
            'marketplace-checkout.core_url' => 'http://core.sandbox.test',
            'marketplace-checkout.transport_secret' => str_repeat('t', 36),
        ]);
        Http::fake();
        $this->assertSame(['status' => 'unavailable'], (new HttpCoreCheckoutAuthority)->readback('b03052ea-3d51-401f-8eca-6cb440417971'));
        Http::assertNothingSent();
    }
}
