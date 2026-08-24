<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/test-idempotent', function () {
        return response()->json(['data' => ['success' => true, 'counter' => rand(1000, 9999)]]);
    })->middleware('idempotent');

    Route::get('/test-driver-only', function () {
        return response()->json(['data' => ['message' => 'driver-allowed']]);
    })->middleware(['auth.jwt', 'role:driver']);
});

test('idempotency middleware caches and replays identical requests', function (): void {
    $key = 'test-key-'.rand(1000, 9999);
    $payload = ['item' => 'shawarma', 'qty' => 2];

    $firstResponse = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/test-idempotent', $payload);

    $firstResponse->assertOk()
        ->assertJsonPath('data.success', true);

    $firstData = $firstResponse->json('data.counter');

    // Second request with same key and body returns exact cached response
    $secondResponse = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/test-idempotent', $payload);

    $secondResponse->assertOk();
    expect($secondResponse->json('data.counter'))->toBe($firstData);

    // Request with same key but different body returns 422 mismatch
    $mismatchResponse = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/test-idempotent', ['item' => 'burger']);

    $mismatchResponse->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_payload_mismatch');
});

test('role middleware forbids customer from accessing driver route', function (): void {
    $customer = User::factory()->create(['role' => 'customer']);
    $driver = User::factory()->driver()->create();

    $jwtService = app(JwtService::class);
    $customerToken = $jwtService->generateAccessToken($customer);
    $driverToken = $jwtService->generateAccessToken($driver);

    // Customer gets 403 Forbidden
    $this->withHeader('Authorization', "Bearer {$customerToken}")
        ->getJson('/test-driver-only')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    // Driver gets 200 OK
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->getJson('/test-driver-only')
        ->assertOk()
        ->assertJsonPath('data.message', 'driver-allowed');
});
