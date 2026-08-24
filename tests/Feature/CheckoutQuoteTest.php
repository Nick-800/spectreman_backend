<?php

declare(strict_types=1);

use App\Events\NewOrderReceived;
use App\Jobs\EnforceOrderTimeoutJob;
use App\Jobs\SendApprovalReminderJob;
use App\Models\SpectreProduct;
use App\Models\SpectreRestaurant;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    SpectreRestaurant::create([
        'id' => '1',
        'name_ar' => 'مطعم السلام',
        'name_en' => 'Al Salam',
        'is_open' => true,
        'opens_at' => '08:00',
        'closes_at' => '23:59',
        'delivery_fee_minor' => 5000,
    ]);

    SpectreRestaurant::create([
        'id' => '2',
        'name_ar' => 'مطعم النجمة',
        'name_en' => 'Al Najma',
        'is_open' => true,
    ]);

    SpectreProduct::create([
        'id' => '12',
        'restaurant_id' => '1',
        'category_id' => '1',
        'title_ar' => 'شاورما',
        'title_en' => 'Shawarma',
        'title_normalized_ar' => 'شاورما',
        'price_minor' => 25000,
        'in_stock' => true,
        'is_active' => true,
    ]);

    SpectreProduct::create([
        'id' => '22',
        'restaurant_id' => '2',
        'category_id' => '1',
        'title_ar' => 'بيتزا',
        'title_en' => 'Pizza',
        'title_normalized_ar' => 'بيتزا',
        'price_minor' => 30000,
        'in_stock' => true,
        'is_active' => true,
    ]);
});

test('cart validate and quote reject mixed items from different restaurants with 409 conflict', function (): void {
    $mixedItems = [
        ['productId' => '12', 'qty' => 1],
        ['productId' => '22', 'qty' => 1],
    ];

    $response = $this->postJson('/api/v1/cart/validate', [
        'items' => $mixedItems,
        'zoneId' => '1',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'cart_vendor_conflict');

    $quoteResponse = $this->postJson('/api/v1/checkout/quote', [
        'items' => $mixedItems,
        'addressId' => '5',
        'paymentMethod' => 'cash',
    ]);

    $quoteResponse->assertStatus(409)
        ->assertJsonPath('error.code', 'cart_vendor_conflict');
});

test('checkout quote returns dynamic breakdown, minor units, and HMAC signature', function (): void {
    $response = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['productId' => '12', 'qty' => 2]],
        'addressId' => '5',
        'couponCode' => 'SPECTRE15',
        'paymentMethod' => 'cash',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.currency', 'LYD')
        ->assertJsonPath('data.totalMinor', 47500) // (25000 * 2) + 5000 - 7500 = 47500
        ->assertJsonPath('data.requiresRestaurantApproval', true)
        ->assertJsonStructure([
            'data' => [
                'quoteId',
                'signature',
                'expiresAt',
                'breakdown',
                'totalMinor',
                'currency',
                'coupon',
            ],
        ]);
});

test('submitting order creates pending approval order, dispatches timeout job, and broadcasts event', function (): void {
    Queue::fake();
    Event::fake();

    Http::fake([
        'http://127.0.0.1:8069/api/v1/internal/orders' => Http::response([
            'data' => [
                'id' => 'odoo_ord_1042',
                'orderNumber' => 'SP-1042',
                'status' => 'awaiting_restaurant_approval',
                'totalMinor' => 47500,
            ],
        ], 201),
    ]);

    $user = User::factory()->create();
    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($user);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => 'ord-key-'.rand(1000, 9999),
    ])->postJson('/api/v1/orders', [
        'quoteId' => 'q_8f3a2b',
        'addressId' => '5',
        'paymentMethod' => 'cash',
        'restaurantId' => '1',
        'totalMinor' => 47500,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.orderNumber', 'SP-1042')
        ->assertJsonPath('data.status', 'awaiting_restaurant_approval')
        ->assertJsonPath('data.approval.timeoutSec', 180);

    $orderId = $response->json('data.id');

    Queue::assertPushed(SendApprovalReminderJob::class, fn ($job) => $job->orderId === $orderId);
    Queue::assertPushed(EnforceOrderTimeoutJob::class, fn ($job) => $job->orderId === $orderId);
    Event::assertDispatched(NewOrderReceived::class, fn ($e) => $e->order->id === $orderId);
});
