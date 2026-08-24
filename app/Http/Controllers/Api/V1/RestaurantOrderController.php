<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\OrderApprovalResolved;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Odoo\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RestaurantOrderController extends Controller
{
    public function __construct(private readonly OdooClient $odooClient) {}

    public function pending(Request $request): JsonResponse
    {
        $orders = Order::where('status', 'awaiting_restaurant_approval')
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('expires_at', 'asc')
            ->get()
            ->map(fn (Order $o): array => [
                'id' => (string) $o->id,
                'orderNumber' => (string) $o->order_number,
                'totalMinor' => (int) $o->total_minor,
                'expiresAt' => $o->expires_at?->toIso8601ZuluString(),
                'remainingSec' => $o->expires_at ? max(0, $o->expires_at->diffInSeconds(Carbon::now())) : 0,
                'createdAt' => $o->created_at?->toIso8601ZuluString(),
                'payload' => $o->payload,
            ]);

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        /** @var Order|null $order */
        $order = Order::find($id);

        if (! $order) {
            return response()->json([
                'error' => [
                    'code' => 'order_not_found',
                    'message' => 'Order not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => (string) $order->id,
                'orderNumber' => (string) $order->order_number,
                'status' => (string) $order->status,
                'totalMinor' => (int) $order->total_minor,
                'expiresAt' => $order->expires_at?->toIso8601ZuluString(),
                'payload' => $order->payload,
            ],
        ]);
    }

    public function accept(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prepMinutes' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        $prepMinutes = (int) $validated['prepMinutes'];

        return DB::transaction(function () use ($id, $prepMinutes): JsonResponse {
            /** @var Order|null $order */
            $order = Order::where('id', $id)->lockForUpdate()->first();

            if (! $order) {
                return response()->json([
                    'error' => [
                        'code' => 'order_not_found',
                        'message' => 'Order not found.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 404);
            }

            if ($order->status !== 'awaiting_restaurant_approval') {
                return response()->json([
                    'error' => [
                        'code' => 'already_decided',
                        'message' => "Order has already been resolved with status: {$order->status}",
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 409);
            }

            if ($order->expires_at && $order->expires_at->isPast()) {
                return response()->json([
                    'error' => [
                        'code' => 'approval_expired',
                        'message' => 'The approval window for this order has expired.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 410);
            }

            $order->update([
                'status' => 'preparing',
                'prep_minutes' => $prepMinutes,
                'decided_at' => Carbon::now(),
            ]);

            $this->odooClient->confirmOrder($order->odoo_order_id, $prepMinutes);

            event(new OrderApprovalResolved($order));

            return response()->json([
                'data' => [
                    'id' => (string) $order->id,
                    'orderNumber' => (string) $order->order_number,
                    'status' => 'preparing',
                    'prepMinutes' => $prepMinutes,
                    'message' => 'Order accepted successfully.',
                ],
            ]);
        });
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reasonCode' => ['required', 'string', 'in:out_of_stock,too_busy,closing,other'],
            'note' => ['nullable', 'string'],
        ]);

        $reasonCode = (string) $validated['reasonCode'];

        return DB::transaction(function () use ($id, $reasonCode): JsonResponse {
            /** @var Order|null $order */
            $order = Order::where('id', $id)->lockForUpdate()->first();

            if (! $order) {
                return response()->json([
                    'error' => [
                        'code' => 'order_not_found',
                        'message' => 'Order not found.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 404);
            }

            if ($order->status !== 'awaiting_restaurant_approval') {
                return response()->json([
                    'error' => [
                        'code' => 'already_decided',
                        'message' => "Order has already been resolved with status: {$order->status}",
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 409);
            }

            $order->update([
                'status' => 'rejected_by_restaurant',
                'rejection_reason' => $reasonCode,
                'decided_at' => Carbon::now(),
            ]);

            $this->odooClient->cancelOrder($order->odoo_order_id, $reasonCode);

            event(new OrderApprovalResolved($order));

            return response()->json([
                'data' => [
                    'id' => (string) $order->id,
                    'orderNumber' => (string) $order->order_number,
                    'status' => 'rejected_by_restaurant',
                    'rejectionReason' => $reasonCode,
                    'message' => 'Order rejected and customer refund triggered.',
                ],
            ]);
        });
    }
}
