<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.odoo.base_url' => 'http://127.0.0.1:8069']);
});

test('login returns token pair and user profile when credentials are valid', function (): void {
    Http::fake([
        'http://127.0.0.1:8069/api/v1/internal/auth/verify' => Http::response([
            'data' => [
                'partnerId' => '581',
                'role' => 'customer',
                'accountState' => 'active',
            ],
        ], 200),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'phone' => '0911234567',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.phone', '+218911234567')
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonPath('data.token.tokenType', 'Bearer')
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'odooPartnerId', 'phone', 'role', 'accountState'],
                'token' => ['tokenType', 'accessToken', 'refreshToken', 'expiresIn'],
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'phone' => '+218911234567',
        'odoo_partner_id' => '581',
    ]);
});

test('login returns 401 with standard error envelope when credentials are wrong', function (): void {
    Http::fake([
        'http://127.0.0.1:8069/api/v1/internal/auth/verify' => Http::response([
            'error' => [
                'code' => 'invalid_credentials',
            ],
        ], 401),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'phone' => '0911234567',
        'password' => 'wrong-pass',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'traceId'],
        ]);
});

test('refresh token rotates cleanly and issues a new token pair', function (): void {
    $user = User::factory()->create([
        'phone' => '+218919999999',
        'role' => 'customer',
        'account_state' => 'active',
    ]);

    $jwtService = app(JwtService::class);
    $initialTokens = $jwtService->issueTokenPair($user);

    $response = $this->postJson('/api/v1/auth/refresh', [
        'refreshToken' => $initialTokens['refreshToken'],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonStructure([
            'data' => [
                'user',
                'token' => ['accessToken', 'refreshToken', 'expiresIn'],
            ],
        ]);

    // Old token should now be revoked
    $secondAttempt = $this->postJson('/api/v1/auth/refresh', [
        'refreshToken' => $initialTokens['refreshToken'],
    ]);

    $secondAttempt->assertStatus(401)
        ->assertJsonPath('error.code', 'token_invalid_or_expired');
});

test('authenticated user can view profile with bearer token', function (): void {
    $user = User::factory()->create([
        'phone' => '+218918888888',
        'role' => 'driver',
        'account_state' => 'active',
    ]);

    $jwtService = app(JwtService::class);
    $token = $jwtService->generateAccessToken($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/profile');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.phone', '+218918888888')
        ->assertJsonPath('data.role', 'driver');
});

test('unauthenticated request to protected route returns 401 in standard envelope', function (): void {
    $response = $this->getJson('/api/v1/auth/profile');

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized');
});
