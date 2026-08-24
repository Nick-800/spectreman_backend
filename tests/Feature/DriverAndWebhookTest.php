<?php

declare(strict_types=1);

use App\Jobs\ProcessInboundWebhookJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['services.odoo.secret' => 'spectreman-test-secret']);
});

test('driver can report presence heartbeat and send high-frequency location pings to buffer', function (): void {
    $driver = User::factory()->driver()->create();
    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($driver);

    // 1. Heartbeat
    $hbRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/presence/heartbeat', [
            'lat' => 32.1150,
            'lng' => 20.0650,
        ]);

    $hbRes->assertOk()
        ->assertJsonPath('data.presence', 'live')
        ->assertJsonPath('data.nextHeartbeatSec', 30);

    // 2. High-frequency location ping
    $pingRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', [
            'lat' => 32.1160,
            'lng' => 20.0670,
            'heading' => 90.0,
            'speed' => 35.5,
            'taskId' => 'task_101',
        ]);

    $pingRes->assertOk()
        ->assertJsonPath('data.received', true);

    $cached = Cache::get("driver:location:{$driver->id}");
    expect($cached['lat'])->toBe(32.1160)
        ->and($cached['heading'])->toBe(90.0);
});

test('driver task delivery enforces geofence boundary', function (): void {
    $driver = User::factory()->driver()->create();
    $customer = User::factory()->create();

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'order_number' => 'SP-3001',
        'odoo_order_id' => 'odoo_3001',
        'user_id' => $customer->id,
        'restaurant_id' => '1',
        'zone_id' => '1',
        'status' => 'on_the_way',
        'total_minor' => 45000,
        'payload' => [],
    ]);

    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($driver);

    // Delivery > 500m away should be rejected with 422
    $farRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/tasks/{$order->id}/deliver", [
            'distanceM' => 650,
        ]);

    $farRes->assertStatus(422)
        ->assertJsonPath('error.code', 'outside_geofence');

    // Delivery <= 500m should succeed
    $closeRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/tasks/{$order->id}/deliver", [
            'distanceM' => 80,
        ]);

    $closeRes->assertOk()
        ->assertJsonPath('data.status', 'delivered');

    $order->refresh();
    expect($order->status)->toBe('delivered')
        ->and($order->payment_status)->toBe('paid');
});

test('inbound odoo webhook verifies HMAC signature and dispatches processing job', function (): void {
    Queue::fake();

    $payload = [
        'eventId' => 'evt_9988',
        'seq' => 101,
        'event' => 'catalog.restaurant_changed',
        'data' => [
            'id' => '1',
            'isOpen' => false,
            'isAcceptingOrders' => false,
        ],
    ];

    $rawBody = (string) json_encode($payload);
    $signature = hash_hmac('sha256', $rawBody, 'spectreman-test-secret');

    // 1. Request with invalid signature gets 401
    $invalidRes = $this->withHeaders([
        'X-Spectre-Signature' => 'sha256=invalid-signature-hash',
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/internal/odoo/webhook', $payload);

    $invalidRes->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_webhook_signature');

    // 2. Request with valid HMAC signature gets 202 Accepted
    $validRes = $this->withHeaders([
        'X-Spectre-Signature' => "sha256={$signature}",
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/internal/odoo/webhook', $payload);

    $validRes->assertStatus(202)
        ->assertJsonPath('data.received', true)
        ->assertJsonPath('data.eventId', 'evt_9988');

    Queue::assertPushed(ProcessInboundWebhookJob::class);
});
