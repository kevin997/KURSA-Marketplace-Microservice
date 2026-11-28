<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckMainApiAuth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MarketplaceCheckoutTest extends TestCase
{
    public function test_checkout_is_unavailable_before_order_or_provider_setup(): void
    {
        $this->withoutMiddleware(CheckMainApiAuth::class);

        $this->postJson('/api/orders', ['items' => []])
            ->assertStatus(503)
            ->assertJsonPath('code', 'checkout_unavailable')
            ->assertJsonMissingPath('payment_url');
    }

    public function test_unverified_payment_entrypoints_are_not_registered(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('POST', $route->methods(), true))
            ->map(fn ($route) => $route->uri());

        $this->assertNotContains('api/webhooks/taramoney', $paths);
        $this->assertNotContains('api/orders/{id}/simulate-payment', $paths);
    }

    public function test_buyer_order_history_still_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }
}
