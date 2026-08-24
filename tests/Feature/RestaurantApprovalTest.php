<?php

declare(strict_types=1);

use App\Events\OrderApprovalResolved;
use App\Jobs\EnforceOrderTimeoutJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Odoo\OdooClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::fake([
        'http://127.0.0.1:8069/api/v1/internal/orders/*/confirm' => Http::response(['success' => true], 200),
        'http://127.0.0.1:8069/api/v1/internal/orders/*/cancel' => Http::response(['success' => true], 200),
    ]);
});

test('restaurant can list pending orders and accept an order within 180s window', function (): void {
    Event::fake();

    $restaurantUser = User::factory()->restaurant()->create();
    $customer = User::factory()->create();

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'order_number' => 'SP-2001',
        'odoo_order_id' => 'odoo_2001',
        'user_id' => $customer->id,
        'restaurant_id' => '1',
        'zone_id' => '1',
        'status' => 'awaiting_restaurant_approval',
        'total_minor' => 50000,
        'expires_at' => Carbon::now()->addSeconds(180),
        'payload' => ['items' => []],
    ]);

    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($restaurantUser);

    // 1. Pending list
    $pendingRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/restaurant/orders/pending');

    $pendingRes->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.orderNumber', 'SP-2001');

    // 2. Accept
    $acceptRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/restaurant/orders/{$order->id}/accept", [
            'prepMinutes' => 25,
        ]);

    $acceptRes->assertOk()
        ->assertJsonPath('data.status', 'preparing')
        ->assertJsonPath('data.prepMinutes', 25);

    $order->refresh();
    expect($order->status)->toBe('preparing')
        ->and($order->prep_minutes)->toBe(25);

    Event::assertDispatched(OrderApprovalResolved::class);

    // 3. Second accept attempt returns 409 already_decided
    $secondAccept = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/restaurant/orders/{$order->id}/accept", [
            'prepMinutes' => 25,
        ]);

    $secondAccept->assertStatus(409)
        ->assertJsonPath('error.code', 'already_decided');
});

test('restaurant can reject an order with reason code and trigger refund', function (): void {
    Event::fake();

    $restaurantUser = User::factory()->restaurant()->create();
    $customer = User::factory()->create();

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'order_number' => 'SP-2002',
        'odoo_order_id' => 'odoo_2002',
        'user_id' => $customer->id,
        'restaurant_id' => '1',
        'zone_id' => '1',
        'status' => 'awaiting_restaurant_approval',
        'total_minor' => 35000,
        'expires_at' => Carbon::now()->addSeconds(180),
        'payload' => ['items' => []],
    ]);

    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($restaurantUser);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/restaurant/orders/{$order->id}/reject", [
            'reasonCode' => 'out_of_stock',
            'note' => 'Shawarma meat finished',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'rejected_by_restaurant')
        ->assertJsonPath('data.rejectionReason', 'out_of_stock');

    $order->refresh();
    expect($order->status)->toBe('rejected_by_restaurant')
        ->and($order->rejection_reason)->toBe('out_of_stock');

    Event::assertDispatched(OrderApprovalResolved::class);
});

test('180-second timeout job auto-rejects unconfirmed order and notifies customer', function (): void {
    Event::fake();

    $customer = User::factory()->create();

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'order_number' => 'SP-2003',
        'odoo_order_id' => 'odoo_2003',
        'user_id' => $customer->id,
        'restaurant_id' => '1',
        'zone_id' => '1',
        'status' => 'awaiting_restaurant_approval',
        'total_minor' => 42000,
        'expires_at' => Carbon::now()->subSecond(),
        'payload' => ['items' => []],
    ]);

    $job = new EnforceOrderTimeoutJob($order->id);
    $job->handle(app(OdooClient::class));

    $order->refresh();
    expect($order->status)->toBe('rejected_by_restaurant')
        ->and($order->rejection_reason)->toBe('timeout');

    Event::assertDispatched(OrderApprovalResolved::class);
});
