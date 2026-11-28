<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Checkout\BootCampsOrderSnapshot;
use App\Services\Checkout\HttpCoreCheckoutAuthority;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketplaceSandboxCheckoutController extends Controller
{
    public function start(Request $request, HttpCoreCheckoutAuthority $core): JsonResponse
    {
        $buyer = $this->requireBuyer($request);
        $validated = $request->validate([
            'product_id' => 'required|integer|min:1',
            'buyer_id' => 'prohibited',
            'amount_minor' => 'prohibited',
            'currency' => 'prohibited',
        ]);

        $snapshot = new BootCampsOrderSnapshot($core);
        try {
            $order = $snapshot->prepare($buyer, (int) $validated['product_id'], $request->header('Idempotency-Key', ''));
        } catch (DomainException) {
            return $this->result(['status' => 'unavailable'], 409);
        }
        $result = $snapshot->startPayment($buyer, $order->id);

        return $this->result($result + ['attempt_id' => $order->id], ($result['status'] ?? null) === 'unavailable' ? 503 : 200);
    }

    public function readback(Request $request, HttpCoreCheckoutAuthority $core): JsonResponse
    {
        $buyer = $this->requireBuyer($request);
        $validated = $request->validate([
            'attempt_id' => 'required|string|uuid',
            'buyer_id' => 'prohibited',
            'amount_minor' => 'prohibited',
            'currency' => 'prohibited',
        ]);
        $result = (new BootCampsOrderSnapshot($core))->reconcile($buyer, $validated['attempt_id']);

        return $this->result(['status' => $result], $result === 'unavailable' ? 409 : 200);
    }

    private function requireBuyer(Request $request): int
    {
        abort_unless(app()->environment('staging', 'testing') && config('marketplace-checkout.enabled') === true, 404);
        $id = data_get($request->attributes->get('verified_marketplace_actor'), 'id');
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 401);

        return (int) $id;
    }

    private function result(array $data, int $code = 200): JsonResponse
    {
        return response()->json($data, $code)->header('Cache-Control', 'no-store');
    }
}
