<?php

namespace App\Services\Checkout;

use App\Models\Product;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class BootCampsOrderSnapshot
{
    private const ENVIRONMENT = 15;

    private const CURRENCY = 'XAF';

    public function __construct(private readonly CoreCheckoutAuthority $core) {}

    /**
     * Prepare one course template using the authenticated Core buyer's ID.
     * The trusted caller supplies actor identity; never use a request-body buyer.
     */
    public function prepare(int $buyerId, int $productId, string $key): stdClass
    {
        if (! $this->enabled() || $buyerId < 1 || $productId < 1 || ! preg_match('/\A[a-z0-9-]{1,80}\z/D', $key)) {
            throw new DomainException('Marketplace checkout is unavailable.');
        }

        try {
            return DB::transaction(function () use ($buyerId, $productId, $key): stdClass {
                $prior = $this->replay($buyerId, $productId, $key);
                if ($prior !== null) {
                    return $prior;
                }

                $product = Product::query()->whereKey($productId)->where('is_published', true)->lockForUpdate()->first();
                $seller = $product?->seller()->lockForUpdate()->first();
                $boundSellerId = config('marketplace-checkout.bootcamps_seller_user_id');
                $quote = config('marketplace-checkout.xaf_prices', [])[$productId] ?? null;
                if (! $product || ! $seller?->is_verified || (int) $seller->environment_id !== self::ENVIRONMENT
                    || ! is_int($boundSellerId) || $boundSellerId < 1 || (int) $seller->user_id !== $boundSellerId
                    || (int) $product->template_id < 1 || ! is_int($quote) || $quote < 1 || $quote > 10000000) {
                    throw new DomainException('Marketplace checkout is unavailable for this template.');
                }

                if ($this->attempts()->where('buyer_id', $buyerId)->where('template_id', (int) $product->template_id)->exists()) {
                    throw new DomainException('An attempt for this template already exists.');
                }

                $id = (string) Str::uuid();
                $this->attempts()->insert([
                    'id' => $id,
                    'buyer_id' => $buyerId,
                    'idempotency_key' => $key,
                    'product_id' => $productId,
                    'template_id' => (int) $product->template_id,
                    'seller_id' => (int) $seller->id,
                    'seller_user_id' => $boundSellerId,
                    'merchant_environment_id' => self::ENVIRONMENT,
                    'product_name' => $product->name,
                    'amount_minor' => $quote,
                    'currency' => self::CURRENCY,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->attempts()->where('id', $id)->first();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($buyerId, $productId, $key)
                ?? throw new DomainException('An attempt for this template already exists.');
        }
    }

    public function startPayment(int $buyerId, string $attemptId): array
    {
        $order = $this->forBuyer($buyerId, $attemptId);
        if (! $order) {
            return ['status' => 'unavailable'];
        }

        $result = $this->core->start($order->id, (int) $order->amount_minor, $order->currency);
        if (! $this->matches($order, $result)) {
            return ['status' => 'unavailable'];
        }

        if (in_array($result['status'] ?? null, ['pending', 'verified'], true)) {
            return ['status' => 'pending'];
        }
        if (($result['status'] ?? null) !== 'ready' || ! is_string($result['payment_url'] ?? null)
            || parse_url($result['payment_url'], PHP_URL_SCHEME) !== 'https'
            || ! parse_url($result['payment_url'], PHP_URL_HOST)) {
            return ['status' => 'unavailable'];
        }

        return [
            'status' => 'ready',
            'attempt_id' => $order->id,
            'amount_minor' => (int) $order->amount_minor,
            'currency' => $order->currency,
            'payment_url' => $result['payment_url'],
        ];
    }

    /**
     * Pull verified evidence directly from Core, not from a webhook or caller.
     * Mark the attempt and queue one entitlement in the same transaction.
     */
    public function reconcile(int $buyerId, string $attemptId): string
    {
        $order = $this->forBuyer($buyerId, $attemptId);
        if (! $order) {
            return 'unavailable';
        }
        $evidence = $this->core->readback($order->id);
        if (! $this->matches($order, $evidence)) {
            return 'unavailable';
        }

        $status = $evidence['status'] ?? null;
        if ($status === 'pending') {
            return 'pending';
        }
        $receipt = $evidence['provider_payment_id'] ?? null;
        if ($status !== 'verified' || ! is_string($receipt) || trim($receipt) === '' || strlen($receipt) > 191) {
            return 'unavailable';
        }

        try {
            return DB::transaction(function () use ($order, $evidence, $receipt): string {
                $current = $this->attempts()->where('id', $order->id)->lockForUpdate()->first();
                if (! $current || ! $this->matches($current, $evidence)) {
                    return 'unavailable';
                }
                if ($current->verified_at !== null) {
                    return $current->provider_payment_id === $receipt ? 'verified' : 'unavailable';
                }

                $this->attempts()->where('id', $current->id)->update([
                    'provider_payment_id' => $receipt,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('marketplace_entitlement_events')->insert([
                    'attempt_id' => $current->id,
                    'buyer_id' => (int) $current->buyer_id,
                    'template_id' => (int) $current->template_id,
                    'seller_id' => (int) $current->seller_id,
                    'created_at' => now(),
                ]);

                return 'verified';
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The provider receipt may already belong to another attempt. Both
            // the attempt state and entitlement insert roll back together.
            return 'unavailable';
        }
    }

    private function enabled(): bool
    {
        return app()->environment('testing', 'staging') && config('marketplace-checkout.enabled') === true;
    }

    private function attempts(): Builder
    {
        return DB::table('marketplace_checkout_attempts');
    }

    private function forBuyer(int $buyerId, string $attemptId): ?stdClass
    {
        if (! $this->enabled() || $buyerId < 1 || ! Str::isUuid($attemptId)) {
            return null;
        }

        return $this->attempts()->where('id', $attemptId)->where('buyer_id', $buyerId)->first();
    }

    private function replay(int $buyerId, int $productId, string $key): ?stdClass
    {
        $previous = $this->attempts()->where('buyer_id', $buyerId)->where('idempotency_key', $key)->first();
        if ($previous !== null && (int) $previous->product_id !== $productId) {
            throw new DomainException('Checkout key cannot be reused for a different template.');
        }

        return $previous;
    }

    private function matches(stdClass $order, array $result): bool
    {
        return (int) $order->merchant_environment_id === self::ENVIRONMENT
            && ($result['attempt_id'] ?? null) === $order->id
            && ($result['amount_minor'] ?? null) === (int) $order->amount_minor
            && ($result['currency'] ?? null) === $order->currency;
    }
}
