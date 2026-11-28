<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckMainApiAuth;
use App\Models\Product;
use App\Models\Seller;
use App\Services\Checkout\BootCampsOrderSnapshot;
use App\Services\Checkout\CoreCheckoutAuthority;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BootCampsOrderSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Run the actual relevant migrations. The legacy category drop is
        // MySQL-specific (it drops an indexed column), not part of this slice.
        foreach ([
            '2025_12_22_133602_create_sellers_table.php',
            '2025_12_22_133603_create_products_table.php',
            '2025_12_22_133603_create_orders_table.php',
            '2026_02_17_220000_add_environment_fields_to_sellers_table.php',
            '2026_11_28_110000_create_marketplace_checkout_evidence.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        config(['marketplace-checkout.enabled' => true, 'marketplace-checkout.bootcamps_seller_user_id' => 48]);
    }

    public function test_snapshots_exact_server_quote_and_replays_immutable_attempt(): void
    {
        $p = $this->product();
        $svc = $this->service();
        $order = $svc->prepare(91, $p->id, 'order-1');
        $this->assertSame(650, (int) $order->amount_minor);
        $this->assertSame('XAF', $order->currency);
        $this->assertSame(53, (int) $order->template_id);
        $this->assertSame($p->seller_id, (int) $order->seller_id);
        config(['marketplace-checkout.xaf_prices' => [$p->id => 800]]);
        $p->update(['name' => 'Renamed', 'price' => '11.23']);
        $replayed = $svc->prepare(91, $p->id, 'order-1');
        $this->assertSame($order->id, $replayed->id);
        $this->assertSame(650, (int) $replayed->amount_minor);
        $this->assertSame('Template test', $replayed->product_name);
        $this->assertDatabaseCount('marketplace_checkout_attempts', 1);
    }

    public function test_wrong_seller_cannot_prepare_checkout_even_when_product_is_published(): void
    {
        $product = $this->product(environment: 18);
        $this->expectException(DomainException::class);
        $this->service()->prepare(91, $product->id, 'order-1');
    }

    public function test_requires_an_explicit_xaf_quote_not_a_legacy_float_price(): void
    {
        $product = $this->product();
        config(['marketplace-checkout.xaf_prices' => [$product->id => '650.50']]);
        $this->expectException(DomainException::class);
        $this->service()->prepare(91, $product->id, 'order-1');
    }

    public function test_another_key_cannot_create_second_attempt_for_same_buyer_and_template(): void
    {
        $p = $this->product();
        $svc = $this->service();
        $svc->prepare(91, $p->id, 'order-1');
        $this->expectException(DomainException::class);
        $svc->prepare(91, $p->id, 'order-2');
    }

    public function test_wrong_amount_creates_no_entitlement_and_valid_replay_creates_only_one(): void
    {
        $p = $this->product();
        $core = $this->createMock(CoreCheckoutAuthority::class);
        $svc = $this->service($core);
        $order = $svc->prepare(91, $p->id, 'order-1');
        $result = ['status' => 'verified', 'attempt_id' => $order->id, 'currency' => 'XAF', 'provider_payment_id' => 'test-receipt'];
        $core->expects($this->exactly(3))->method('readback')->with($order->id)
            ->willReturnOnConsecutiveCalls($result + ['amount_minor' => 600], $result + ['amount_minor' => 650], $result + ['amount_minor' => 650]);

        $this->assertSame('unavailable', $svc->reconcile(91, $order->id));
        $this->assertDatabaseCount('marketplace_entitlement_events', 0);
        $this->assertSame('verified', $svc->reconcile(91, $order->id));
        $this->assertSame('verified', $svc->reconcile(91, $order->id));
        $this->assertDatabaseCount('marketplace_entitlement_events', 1);
        $this->assertDatabaseHas('marketplace_entitlement_events', [
            'attempt_id' => $order->id, 'buyer_id' => 91, 'template_id' => 53, 'seller_id' => $p->seller_id,
        ]);
    }

    public function test_foreign_buyer_cannot_fetch_payment_session_or_trigger_readback(): void
    {
        $p = $this->product();
        $core = $this->createMock(CoreCheckoutAuthority::class);
        $core->expects($this->never())->method('start');
        $core->expects($this->never())->method('readback');
        $svc = $this->service($core);
        $order = $svc->prepare(91, $p->id, 'order-1');

        $this->assertSame(['status' => 'unavailable'], $svc->startPayment(92, $order->id));
        $this->assertSame('unavailable', $svc->reconcile(92, $order->id));
    }

    public function test_gateway_is_called_with_the_immutable_amount_only(): void
    {
        $p = $this->product();
        $core = $this->createMock(CoreCheckoutAuthority::class);
        $svc = $this->service($core);
        $order = $svc->prepare(91, $p->id, 'order-1');
        $result = ['status' => 'ready', 'attempt_id' => $order->id, 'amount_minor' => 650, 'currency' => 'XAF', 'payment_url' => 'https://pay.test.local/session'];
        $core->expects($this->once())->method('start')->with($order->id, 650, 'XAF')->willReturn($result);
        $this->assertSame($result, $svc->startPayment(91, $order->id));
        $this->assertDatabaseCount('marketplace_entitlement_events', 0);
    }

    public function test_reused_receipt_cannot_grant_another_buyers_entitlement(): void
    {
        $p = $this->product();
        $core = $this->createMock(CoreCheckoutAuthority::class);
        $svc = $this->service($core);
        $one = $svc->prepare(91, $p->id, 'order-1');
        $two = $svc->prepare(92, $p->id, 'order-1');
        $core->expects($this->exactly(2))->method('readback')->willReturnCallback(
            fn ($id) => ['status' => 'verified', 'attempt_id' => $id, 'amount_minor' => 650, 'currency' => 'XAF', 'provider_payment_id' => 'test-receipt'],
        );
        $this->assertSame('verified', $svc->reconcile(91, $one->id));
        $this->assertSame('unavailable', $svc->reconcile(92, $two->id));
        $this->assertDatabaseCount('marketplace_entitlement_events', 1);
    }

    public function test_existing_paid_legacy_order_does_not_grant_content_and_disabled_checkout_does_no_work(): void
    {
        $p = $this->product();
        DB::table('orders')->insert(['order_number' => 'TEST-LEGACY', 'user_id' => 91, 'total_amount' => 1, 'status' => 'paid']);
        config(['marketplace-checkout.enabled' => false]);
        $svc = $this->service();
        $this->assertSame('unavailable', $svc->reconcile(91, 'ce122b09-3c59-4edd-acf1-167ed5f37f79'));
        $this->assertDatabaseCount('marketplace_entitlement_events', 0);
        $this->expectException(DomainException::class);
        $svc->prepare(91, $p->id, 'order-1');
    }

    public function test_sandbox_buyer_route_prepares_and_fulfils_once_through_core_contract(): void
    {
        $product = $this->product();
        config([
            'marketplace-checkout.core_url' => 'https://checkout-core.example.test',
            'marketplace-checkout.transport_secret' => str_repeat('s', 36),
        ]);
        // Only the authenticated attribute is available to the controller.
        // Stub the separate Core identity service but exercise the real route.
        $this->app->bind(CheckMainApiAuth::class, fn () => new class
        {
            public function handle($request, $next)
            {
                $request->attributes->set('verified_marketplace_actor', ['id' => 91]);

                return $next($request);
            }
        });
        Http::preventStrayRequests()->fake([
            'https://checkout-core.example.test/api/internal/marketplace/collection/start' => fn (Request $r) => Http::response([
                'status' => 'ready', 'attempt_id' => $r['attempt_id'], 'amount_minor' => $r['amount_minor'],
                'currency' => $r['currency'], 'payment_url' => 'https://tara.example.test/payment',
            ]),
            'https://checkout-core.example.test/api/internal/marketplace/collection/readback' => fn (Request $r) => Http::response([
                'status' => 'verified', 'attempt_id' => $r['attempt_id'], 'amount_minor' => 650,
                'currency' => 'XAF', 'provider_payment_id' => 'route-fixture-receipt',
            ]),
        ]);
        $route = '/api/sandbox-checkout/start';
        $headers = ['Idempotency-Key' => 'fixture-key'];
        $this->postJson($route, ['product_id' => $product->id, 'amount_minor' => 1], $headers)->assertUnprocessable();
        Http::assertNothingSent();
        $id = $this->postJson($route, ['product_id' => $product->id], $headers)
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('amount_minor', 650)->json('attempt_id');
        $this->assertDatabaseCount('marketplace_entitlement_events', 0);
        $this->postJson('/api/sandbox-checkout/readback', ['attempt_id' => $id])->assertOk()->assertExactJson(['status' => 'verified']);
        $this->postJson('/api/sandbox-checkout/readback', ['attempt_id' => $id])->assertOk()->assertExactJson(['status' => 'verified']);
        $this->assertDatabaseCount('marketplace_entitlement_events', 1);
    }

    private function service(?CoreCheckoutAuthority $core = null): BootCampsOrderSnapshot
    {
        return new BootCampsOrderSnapshot($core ?? $this->createMock(CoreCheckoutAuthority::class));
    }

    private function product(int $environment = 15): Product
    {
        $seller = Seller::create(['user_id' => 48, 'company_name' => 'BootCamps fixture', 'email' => 'sandbox@example.test', 'environment_id' => $environment, 'is_verified' => true]);
        $p = Product::create(['seller_id' => $seller->id, 'name' => 'Template test', 'description' => 'Test', 'price' => '1.50', 'template_id' => 53, 'is_published' => true]);
        config(['marketplace-checkout.xaf_prices' => [$p->id => 650]]);

        return $p;
    }
}
