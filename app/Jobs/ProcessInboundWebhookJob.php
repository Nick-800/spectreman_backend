<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\OrderApprovalResolved;
use App\Models\Order;
use App\Models\SpectreProduct;
use App\Models\SpectreRestaurant;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ProcessInboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
    ) {}

    public function handle(JwtService $jwtService): void
    {
        Log::info("Processing inbound Odoo webhook: {$this->eventType}", ['payload' => $this->payload]);

        match ($this->eventType) {
            'catalog.restaurant_changed' => $this->handleRestaurantChanged(),
            'catalog.stock_changed' => $this->handleStockChanged(),
            'user.account_state_changed' => $this->handleUserStateChanged($jwtService),
            'order.approval_resolved' => $this->handleOrderApprovalResolved(),
            default => Log::info("Unhandled Odoo webhook event: {$this->eventType}"),
        };
    }

    private function handleRestaurantChanged(): void
    {
        $id = (string) ($this->payload['id'] ?? '');
        if (! $id) {
            return;
        }

        $restaurant = SpectreRestaurant::find($id);
        if ($restaurant) {
            $restaurant->update([
                'is_open' => (bool) ($this->payload['isOpen'] ?? $restaurant->is_open),
                'is_accepting_orders' => (bool) ($this->payload['isAcceptingOrders'] ?? $restaurant->is_accepting_orders),
            ]);

            // Bump catalog version for cache invalidation
            Cache::put('spectre.catalog_version', now()->format('Y-m-d-His'));
        }
    }

    private function handleStockChanged(): void
    {
        $productId = (string) ($this->payload['productId'] ?? '');
        if (! $productId) {
            return;
        }

        $product = SpectreProduct::find($productId);
        if ($product) {
            $product->update([
                'in_stock' => (bool) ($this->payload['inStock'] ?? $product->in_stock),
            ]);
        }
    }

    private function handleUserStateChanged(JwtService $jwtService): void
    {
        $partnerId = (string) ($this->payload['partnerId'] ?? '');
        $state = (string) ($this->payload['accountState'] ?? 'active');

        if ($partnerId) {
            $user = User::where('odoo_partner_id', $partnerId)->first();
            if ($user) {
                $user->update(['account_state' => $state]);

                if ($state === 'suspended') {
                    $jwtService->revokeAllUserSessions($user->id);
                }
            }
        }
    }

    private function handleOrderApprovalResolved(): void
    {
        $orderId = (string) ($this->payload['orderId'] ?? '');
        $verdict = (string) ($this->payload['verdict'] ?? '');

        if ($orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $status = match ($verdict) {
                    'accepted' => 'preparing',
                    default => 'rejected_by_restaurant',
                };

                $order->update([
                    'status' => $status,
                    'rejection_reason' => $verdict === 'rejected' ? ($this->payload['reason'] ?? null) : null,
                    'prep_minutes' => $this->payload['prepMinutes'] ?? null,
                    'decided_at' => now(),
                ]);

                event(new OrderApprovalResolved($order));
            }
        }
    }
}
