<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\KafkaProducerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * POST /api/orders - Create an order from cart items.
     * Requires auth (main_api.auth middleware).
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
        ]);

        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;

        if (!$userId) {
            return response()->json(['message' => 'User ID not found'], 400);
        }

        // Fetch products and calculate total
        $productIds = collect($request->items)->pluck('product_id')->unique();
        $products = Product::whereIn('id', $productIds)
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            return response()->json(['message' => 'One or more products are unavailable'], 400);
        }

        $totalAmount = 0;
        $itemsData = [];

        foreach ($request->items as $item) {
            $product = $products[$item['product_id']];
            $totalAmount += $product->price;
            $itemsData[] = [
                'product_id' => $product->id,
                'price' => $product->price,
            ];
        }

        // Create order
        $order = Order::create([
            'order_number' => 'ORD-' . strtoupper(Str::random(8)),
            'user_id' => $userId,
            'total_amount' => $totalAmount,
            'status' => 'pending',
        ]);

        // Create order items
        foreach ($itemsData as $itemData) {
            OrderItem::create(array_merge($itemData, ['order_id' => $order->id]));
        }

        // Create pending transaction
        $transaction = Transaction::create([
            'order_id' => $order->id,
            'payment_provider' => 'taramoney',
            'amount' => $totalAmount,
            'status' => 'pending',
        ]);

        $order->load('items.product');

        return response()->json([
            'order' => $order,
            'transaction' => $transaction,
            'payment_url' => $this->generateTaraMoneyPaymentUrl($order, $transaction),
        ], 201);
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

    /**
     * POST /api/webhooks/taramoney - Tara Money payment callback.
     * This endpoint is called by Tara Money when payment status changes.
     * No auth middleware — verified by webhook secret.
     */
    public function taraMoneyWebhook(Request $request)
    {
        // Verify webhook signature (placeholder)
        $webhookSecret = config('services.taramoney.webhook_secret');
        // In production, verify $request->header('X-TaraMoney-Signature')

        $transactionId = $request->input('transaction_id');
        $status = $request->input('status'); // 'success' | 'failed'
        $orderNumber = $request->input('order_number');

        $order = Order::where('order_number', $orderNumber)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $transaction = Transaction::where('order_id', $order->id)
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found or already processed'], 400);
        }

        if ($status === 'success') {
            // Update transaction
            $transaction->update([
                'transaction_id' => $transactionId,
                'status' => 'completed',
                'payload' => $request->all(),
            ]);

            // Update order status
            $order->update(['status' => 'paid']);

            // Load order items with product details for Kafka event
            $order->load('items.product');

            // Publish Kafka event for CSL-Certification-Rest-API to consume
            $kafka = new KafkaProducerService();
            $kafka->publishPurchaseCompleted([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'total_amount' => $order->total_amount,
                'items' => $order->items->map(fn($item) => [
                    'product_id' => $item->product_id,
                    'template_id' => $item->product->template_id,
                    'product_name' => $item->product->name,
                    'price' => $item->price,
                ])->toArray(),
                'paid_at' => now()->toIso8601String(),
            ]);

            return response()->json(['message' => 'Payment processed successfully']);
        }

        // Payment failed
        $transaction->update([
            'transaction_id' => $transactionId,
            'status' => 'failed',
            'payload' => $request->all(),
        ]);
        $order->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Payment failed, order cancelled']);
    }

    /**
     * POST /api/orders/{id}/simulate-payment - DEV ONLY: simulate successful payment.
     */
    public function simulatePayment(Request $request, $id)
    {
        if (!app()->environment('local', 'development', 'testing')) {
            return response()->json(['message' => 'Not available in production'], 403);
        }

        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;

        $order = Order::where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->firstOrFail();

        $transaction = Transaction::where('order_id', $order->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $transaction->update([
            'transaction_id' => 'SIM-' . Str::random(12),
            'status' => 'completed',
        ]);

        $order->update(['status' => 'paid']);
        $order->load('items.product');

        // Publish Kafka event
        $kafka = new KafkaProducerService();
        $kafka->publishPurchaseCompleted([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'total_amount' => $order->total_amount,
            'items' => $order->items->map(fn($item) => [
                'product_id' => $item->product_id,
                'template_id' => $item->product->template_id,
                'product_name' => $item->product->name,
                'price' => $item->price,
            ])->toArray(),
            'paid_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Payment simulated successfully',
            'order' => $order,
        ]);
    }

    /**
     * Generate Tara Money payment URL (placeholder).
     */
    private function generateTaraMoneyPaymentUrl(Order $order, Transaction $transaction): string
    {
        $baseUrl = config('services.taramoney.base_url', 'https://pay.taramoney.com');
        $merchantId = config('services.taramoney.merchant_id', 'KURSA_MARKETPLACE');

        // In real implementation, this would call Tara Money API to create a payment session
        return "{$baseUrl}/checkout?merchant={$merchantId}&order={$order->order_number}&amount={$order->total_amount}&callback=" . urlencode(config('app.url') . '/api/webhooks/taramoney');
    }
}
