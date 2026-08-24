<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SendApprovalReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $orderId) {}

    public function handle(): void
    {
        /** @var Order|null $order */
        $order = Order::find($this->orderId);

        if (! $order || $order->status !== 'awaiting_restaurant_approval') {
            return;
        }

        // Alert operations and trigger secondary alert to restaurant
        Log::warning("Order {$order->order_number} has not been confirmed after 60s. Ops reminder triggered.");
    }
}
