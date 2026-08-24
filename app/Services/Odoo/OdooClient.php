<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OdooClient
{
    private string $baseUrl;

    private string $secret;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.odoo.base_url', 'http://127.0.0.1:8069'), '/');
        $this->secret = (string) config('services.odoo.secret', 'spectreman-internal-odoo-secret');
        $this->timeout = (int) config('services.odoo.timeout', 15);
    }

    /**
     * Authenticate and verify user credentials with Odoo.
     *
     * @return array{success: bool, partnerId?: string, role?: string, accountState?: string, error?: string}
     */
    public function verifyCredentials(string $phone, string $password): array
    {
        $response = $this->http()->post("{$this->baseUrl}/api/v1/internal/auth/verify", [
            'phone' => $phone,
            'password' => $password,
        ]);

        if ($response->successful()) {
            /** @var array{data: array{partnerId: string, role: string, accountState: string}} $data */
            $data = $response->json();

            return [
                'success' => true,
                'partnerId' => (string) ($data['data']['partnerId'] ?? ''),
                'role' => (string) ($data['data']['role'] ?? 'customer'),
                'accountState' => (string) ($data['data']['accountState'] ?? 'active'),
            ];
        }

        $error = $response->json('error.code', 'invalid_credentials');

        return [
            'success' => false,
            'error' => (string) $error,
        ];
    }

    /**
     * Submit order creation payload to Odoo.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, orderNumber?: string, odooOrderId?: string, status?: string, totalMinor?: int, error?: string}
     */
    public function createOrder(array $payload, string $actingUserId, ?string $idempotencyKey = null): array
    {
        $headers = ['X-Acting-User' => $actingUserId];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->http($headers)->post("{$this->baseUrl}/api/v1/internal/orders", $payload);

        if ($response->successful()) {
            /** @var array{data: array{orderNumber: string, id: string, status: string, totalMinor: int}} $data */
            $data = $response->json();

            return [
                'success' => true,
                'orderNumber' => (string) ($data['data']['orderNumber'] ?? ''),
                'odooOrderId' => (string) ($data['data']['id'] ?? ''),
                'status' => (string) ($data['data']['status'] ?? 'awaiting_restaurant_approval'),
                'totalMinor' => (int) ($data['data']['totalMinor'] ?? 0),
            ];
        }

        return [
            'success' => false,
            'error' => (string) $response->json('error.code', 'order_creation_failed'),
        ];
    }

    /**
     * Cancel an order in Odoo and trigger automated wallet refund.
     *
     * @return array{success: bool, error?: string}
     */
    public function cancelOrder(string $odooOrderId, string $reasonCode): array
    {
        $response = $this->http()->post("{$this->baseUrl}/api/v1/internal/orders/{$odooOrderId}/cancel", [
            'reasonCode' => $reasonCode,
        ]);

        if ($response->successful()) {
            return ['success' => true];
        }

        Log::error("Failed to cancel Odoo order {$odooOrderId}", ['response' => $response->body()]);

        return [
            'success' => false,
            'error' => (string) $response->json('error.code', 'order_cancellation_failed'),
        ];
    }

    /**
     * Confirm a restaurant accepted order in Odoo.
     *
     * @return array{success: bool, error?: string}
     */
    public function confirmOrder(string $odooOrderId, int $prepMinutes): array
    {
        $response = $this->http()->post("{$this->baseUrl}/api/v1/internal/orders/{$odooOrderId}/confirm", [
            'prepMinutes' => $prepMinutes,
        ]);

        if ($response->successful()) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => (string) $response->json('error.code', 'order_confirmation_failed'),
        ];
    }

    /**
     * Get dynamic pricing quote from Odoo.
     *
     * @param  array<int, array{productId: string, qty: int}>  $items
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function getQuote(array $items, string $addressId, ?string $couponCode, string $paymentMethod, string $actingUserId): array
    {
        $response = $this->http(['X-Acting-User' => $actingUserId])->post("{$this->baseUrl}/api/v1/internal/checkout/quote", [
            'items' => $items,
            'addressId' => $addressId,
            'couponCode' => $couponCode,
            'paymentMethod' => $paymentMethod,
        ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => (array) $response->json('data'),
            ];
        }

        return [
            'success' => false,
            'error' => (string) $response->json('error.code', 'quote_calculation_failed'),
        ];
    }

    /**
     * @param  array<string, string>  $customHeaders
     */
    private function http(array $customHeaders = []): PendingRequest
    {
        return Http::withHeaders(array_merge([
            'Authorization' => "Bearer {$this->secret}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $customHeaders))
            ->timeout($this->timeout)
            ->retry(2, 100, throw: false);
    }
}
