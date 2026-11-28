<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * No provider session is available yet. Do not create an order or expose
     * a payment redirect until checkout has an authoritative settlement path.
     */
    public function store()
    {
        return response()->json([
            'code' => 'checkout_unavailable',
            'message' => 'Marketplace checkout is currently unavailable.',
        ], 503);
    }

    /**
     * GET /api/orders - List user's orders.
     */
    public function index(Request $request)
    {
        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;

        $orders = Order::where('user_id', $userId)
            ->with('items.product.seller')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($orders);
    }

    /**
     * GET /api/orders/{id} - Show order details.
     */
    public function show(Request $request, $id)
    {
        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;

        $order = Order::where('id', $id)
            ->where('user_id', $userId)
            ->with(['items.product.seller', 'transactions'])
            ->firstOrFail();

        return response()->json($order);
    }
}
