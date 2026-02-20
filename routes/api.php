<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SellerController;

// Public Routes
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show'])->where('id', '[0-9]+');

// Tara Money Webhook (no auth - verified by webhook secret)
Route::post('/webhooks/taramoney', [OrderController::class, 'taraMoneyWebhook']);

// Protected Routes (Remote User via Main API token)
Route::middleware('main_api.auth')->group(function () {
    Route::get('/user', function (Request $request) {
        $remoteUser = $request->get('remote_user');
        $userId = $remoteUser['id'] ?? null;

        // Include local seller data (synced on auth by middleware)
        if ($userId) {
            $seller = \App\Models\Seller::where('user_id', $userId)->first();
            if ($seller) {
                $remoteUser['seller'] = [
                    'id' => $seller->id,
                    'company_name' => $seller->company_name,
                    'environment_url' => $seller->environment_url,
                    'environment_name' => $seller->environment_name,
                    'logo_url' => $seller->logo_url,
                    'environment_id' => $seller->environment_id,
                    'is_verified' => $seller->is_verified,
                ];
            }
        }

        return $remoteUser;
    });

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/simulate-payment', [OrderController::class, 'simulatePayment']);

    // Seller Panel (via external token auth — triggers callback to Main API)
    Route::prefix('seller')->group(function () {
        Route::get('/dashboard', [SellerController::class, 'dashboard']);
        Route::get('/orders', [SellerController::class, 'orders']);
        Route::get('/orders/{id}', [SellerController::class, 'orderShow']);
        Route::get('/listings', [SellerController::class, 'listings']);
        Route::put('/listings/{id}', [SellerController::class, 'updateListing']);
        Route::delete('/listings/{id}', [SellerController::class, 'deleteListing']);
    });
});

// Internal service-to-service routes (no callback to Main API — avoids deadlock)
Route::middleware('internal.auth')->prefix('internal')->group(function () {
    Route::prefix('seller')->group(function () {
        Route::get('/dashboard', [SellerController::class, 'dashboard']);
        Route::get('/orders', [SellerController::class, 'orders']);
        Route::get('/orders/{id}', [SellerController::class, 'orderShow']);
        Route::get('/listings', [SellerController::class, 'listings']);
        Route::put('/listings/{id}', [SellerController::class, 'updateListing']);
        Route::delete('/listings/{id}', [SellerController::class, 'deleteListing']);
    });

    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::put('/{id}', [CategoryController::class, 'update']);
        Route::delete('/{id}', [CategoryController::class, 'destroy']);
    });
});
