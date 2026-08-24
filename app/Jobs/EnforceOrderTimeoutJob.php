<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\OrderApprovalResolved;
use App\Models\Order;
use App\Services\Odoo\OdooClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EnforceOrderTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly string $orderId) {}

    public function handle(OdooClient $odooClient): void
    {
        DB::transaction(function () use ($odooClient): void {
            /** @var Order|null $order */
            $order = Order::where('id', $this->orderId)->lockForUpdate()->first();

            if (! $order || $order->status !== 'awaiting_restaurant_approval') {
                return; // Already resolved (accepted, rejected, or cancelled)
            }

            $order->update([
                'status' => 'rejected_by_restaurant',
                'rejection_reason' => 'timeout',
                'decided_at' => Carbon::now(),
            ]);

            // Instruct Odoo to cancel order and refund to customer wallet
            $odooClient->cancelOrder($order->odoo_order_id, 'timeout');

            // Broadcast resolution to customer & restaurant console
            event(new OrderApprovalResolved($order));

            Log::info("Order {$order->order_number} timed out after 180s and was auto-rejected.");
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("EnforceOrderTimeoutJob failed for order {$this->orderId}", ['error' => $e->getMessage()]);
    }
}
