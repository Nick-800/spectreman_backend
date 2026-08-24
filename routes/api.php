<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\RestaurantOrderController;
use App\Http\Controllers\Api\V1\TrackingController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\VerifyOdooWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Prefix: /api/v1)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');

    Route::middleware('auth.jwt')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
    });
});

Route::get('/config', [ConfigController::class, 'show']);

Route::prefix('geo')->group(function (): void {
    Route::get('/ref-data', [GeoController::class, 'refData']);
});

Route::post('/delivery/check', [GeoController::class, 'checkDelivery']);
Route::get('/geocode/reverse', [GeoController::class, 'reverseGeocode']);

Route::get('/home', [CatalogController::class, 'home']);
Route::get('/products', [CatalogController::class, 'products']);
Route::get('/products/{id}', [CatalogController::class, 'productDetail']);
Route::get('/search/suggest', [CatalogController::class, 'suggest']);
Route::get('/categories', [CatalogController::class, 'categories']);
Route::get('/restaurants', [CatalogController::class, 'restaurants']);
Route::get('/restaurants/{id}', [CatalogController::class, 'restaurantDetail']);

// Cart & Quoting
Route::post('/cart/validate', [CartController::class, 'validateCart']);
Route::post('/checkout/quote', [CartController::class, 'quote']);

// Customer Orders
Route::middleware('auth.jwt')->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
});

// Restaurant Console Endpoints
Route::middleware(['auth.jwt', 'role:restaurant'])->prefix('restaurant')->group(function (): void {
    Route::get('/orders/pending', [RestaurantOrderController::class, 'pending']);
    Route::get('/orders/{id}', [RestaurantOrderController::class, 'show']);
    Route::post('/orders/{id}/accept', [RestaurantOrderController::class, 'accept']);
    Route::post('/orders/{id}/reject', [RestaurantOrderController::class, 'reject']);
});

// Tracking (Customer App)
Route::middleware('auth.jwt')->prefix('orders')->group(function (): void {
    Route::get('/{id}/tracking', [TrackingController::class, 'show']);
    Route::get('/{id}/tracking/stream', [TrackingController::class, 'stream']);
});

// Driver Operations
Route::middleware(['auth.jwt', 'role:driver'])->prefix('driver')->group(function (): void {
    Route::post('/presence/heartbeat', [DriverController::class, 'heartbeat']);
    Route::post('/location', [DriverController::class, 'location']);
    Route::post('/tasks/{id}/{transition}', [DriverController::class, 'transition']);
});

// Inbound Odoo Webhooks
Route::post('/internal/odoo/webhook', [WebhookController::class, 'handle'])
    ->middleware(VerifyOdooWebhookSignature::class);
