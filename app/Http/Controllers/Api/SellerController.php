<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    public function dashboard(Request $request)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $productIds = Product::where('seller_id', $seller->id)->pluck('id');
        $totalListings = Product::where('seller_id', $seller->id)->count();
        $activeListings = Product::where('seller_id', $seller->id)->where('is_published', true)->count();

        $paidItemQuery = OrderItem::whereIn('product_id', $productIds)
            ->whereHas('order', fn($q) => $q->where('status', 'paid'));
        $totalSold = (clone $paidItemQuery)->count();
        $totalRevenue = (clone $paidItemQuery)->sum('price');

        $pendingOrders = OrderItem::whereIn('product_id', $productIds)
            ->whereHas('order', fn($q) => $q->where('status', 'pending'))
            ->distinct()->count('order_id');

        $recentOrderIds = OrderItem::whereIn('product_id', $productIds)
            ->select('order_id')->distinct()->orderBy('order_id', 'desc')
            ->limit(5)->pluck('order_id');

        $recentOrders = Order::whereIn('id', $recentOrderIds)
            ->with(['items' => fn($q) => $q->whereIn('product_id', $productIds)->with('product')])
            ->orderBy('created_at', 'desc')->get()
            ->map(fn($o) => $this->formatSellerOrder($o));

        $monthlyRevenue = OrderItem::whereIn('product_id', $productIds)
            ->whereHas('order', fn($q) => $q->where('status', 'paid'))
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->select(DB::raw('DATE_FORMAT(orders.created_at, "%Y-%m") as month'), DB::raw('SUM(order_items.price) as revenue'), DB::raw('COUNT(order_items.id) as sales_count'))
            ->where('orders.created_at', '>=', now()->subMonths(6))
            ->groupBy('month')->orderBy('month')->get();

        return response()->json([
            'stats' => compact('totalListings', 'activeListings', 'totalSold', 'totalRevenue', 'pendingOrders'),
            'recent_orders' => $recentOrders,
            'monthly_revenue' => $monthlyRevenue,
            'seller' => ['id' => $seller->id, 'company_name' => $seller->company_name, 'is_verified' => $seller->is_verified],
        ]);
    }

    public function orders(Request $request)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $productIds = Product::where('seller_id', $seller->id)->pluck('id');
        $orderIds = OrderItem::whereIn('product_id', $productIds)->distinct()->pluck('order_id');

        $query = Order::whereIn('id', $orderIds)
            ->with(['items' => fn($q) => $q->whereIn('product_id', $productIds)->with('product')]);

        $status = $request->get('status');
        if ($status && in_array($status, ['pending', 'paid', 'cancelled'])) {
            $query->where('status', $status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));
        $orders->getCollection()->transform(fn($o) => $this->formatSellerOrder($o));
        return response()->json($orders);
    }

    public function orderShow(Request $request, $id)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $productIds = Product::where('seller_id', $seller->id)->pluck('id');
        if (!OrderItem::where('order_id', $id)->whereIn('product_id', $productIds)->exists()) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order = Order::where('id', $id)
            ->with(['items' => fn($q) => $q->whereIn('product_id', $productIds)->with('product'), 'transactions'])
            ->firstOrFail();

        return response()->json($this->formatSellerOrder($order));
    }

    public function listings(Request $request)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $query = Product::where('seller_id', $seller->id)->with('categories');

        if ($request->has('is_published')) {
            $query->where('is_published', filter_var($request->is_published, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->has('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"));
        }
        if ($request->has('template_id')) {
            $query->where('template_id', $request->template_id);
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        $salesData = OrderItem::whereIn('product_id', $products->getCollection()->pluck('id'))
            ->whereHas('order', fn($q) => $q->where('status', 'paid'))
            ->select('product_id', DB::raw('COUNT(*) as sales_count'), DB::raw('SUM(price) as revenue'))
            ->groupBy('product_id')->get()->keyBy('product_id');

        $products->getCollection()->transform(function ($p) use ($salesData) {
            $s = $salesData->get($p->id);
            $p->sales_count = $s ? $s->sales_count : 0;
            $p->revenue = $s ? round($s->revenue, 2) : 0;
            return $p;
        });

        return response()->json($products);
    }

    public function updateListing(Request $request, $id)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $product = Product::where('id', $id)->where('seller_id', $seller->id)->firstOrFail();
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000',
            'price' => 'sometimes|numeric|min:0.01|max:99999.99',
            'is_published' => 'sometimes|boolean',
            'thumbnail_url' => 'sometimes|nullable|string|max:500',
        ]);
        $product->update($request->only(['name', 'description', 'price', 'is_published', 'thumbnail_url']));
        return response()->json(['message' => 'Listing updated', 'product' => $product->fresh()->load('categories')]);
    }

    public function deleteListing(Request $request, $id)
    {
        $seller = $this->getAuthenticatedSeller($request);
        if (!$seller) {
            return response()->json(['message' => 'Seller profile not found'], 404);
        }

        $product = Product::where('id', $id)->where('seller_id', $seller->id)->firstOrFail();
        $product->update(['is_published' => false]);
        return response()->json(['message' => 'Listing unpublished']);
    }

    private function getAuthenticatedSeller(Request $request): ?Seller
    {
        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;
        return $userId ? Seller::where('user_id', $userId)->first() : null;
    }

    private function formatSellerOrder(Order $order): array
    {
        $sellerRevenue = $order->items->sum('price');
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'seller_revenue' => round($sellerRevenue, 2),
            'total_amount' => $order->total_amount,
            'items' => $order->items->map(fn($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'product_thumbnail' => $i->product?->thumbnail_url,
                'price' => $i->price,
            ]),
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }
}
