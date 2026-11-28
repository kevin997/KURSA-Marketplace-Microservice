<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VerifiedSandboxEntitlementController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('marketplace-checkout.transport_secret');
        abort_unless(app()->environment('staging', 'testing')
            && config('marketplace-checkout.enabled') === true
            && is_string($secret) && strlen($secret) >= 32, 404);

        $token = $request->bearerToken();
        abort_unless(is_string($token) && hash_equals($secret, $token), 401);

        $query = $request->validate([
            'buyer_id' => 'required|integer|min:1',
            'template_id' => 'required|integer|min:1',
        ]);
        $entry = DB::table('marketplace_entitlement_events as entitlement')
            ->join('marketplace_checkout_attempts as snapshot', 'snapshot.id', '=', 'entitlement.attempt_id')
            ->where('entitlement.buyer_id', $query['buyer_id'])
            ->where('entitlement.template_id', $query['template_id'])
            ->whereColumn('entitlement.buyer_id', 'snapshot.buyer_id')
            ->whereColumn('entitlement.template_id', 'snapshot.template_id')
            ->whereColumn('entitlement.seller_id', 'snapshot.seller_id')
            ->where('snapshot.merchant_environment_id', 15)
            ->whereNotNull('snapshot.verified_at')
            ->whereNotNull('snapshot.provider_payment_id')
            ->first(['snapshot.id', 'snapshot.buyer_id', 'snapshot.template_id', 'snapshot.merchant_environment_id']);

        abort_unless($entry, 404);

        return response()->json([
            'receipt_id' => $entry->id,
            'buyer_id' => (int) $entry->buyer_id,
            'template_id' => (int) $entry->template_id,
            'source_academy_id' => (int) $entry->merchant_environment_id,
        ])->header('Cache-Control', 'no-store');
    }
}
