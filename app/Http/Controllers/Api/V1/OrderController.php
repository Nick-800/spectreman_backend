<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\NewOrderReceived;
use App\Http\Controllers\Controller;
use App\Jobs\EnforceOrderTimeoutJob;
use App\Jobs\SendApprovalReminderJob;
use App\Models\Order;
use App\Models\User;
use App\Services\Odoo\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class OrderController extends Controller
{
    public function __construct(private readonly OdooClient $odooClient) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quoteId' => ['required', 'string'],
            'addressId' => ['required', 'string'],
            'paymentMethod' => ['required', 'string'],
            'notes' => ['nullable', 'array'],
            'notes.store' => ['nullable', 'string'],
            'notes.driver' => ['nullable', 'string'],
            'restaurantId' => ['nullable', 'string'],
            'totalMinor' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Call Odoo to mint the order number and register the quote conversion
        $odooResult = $this->odooClient->createOrder(
            $validated,
            $user->id,
            $request->header('Idempotency-Key'),
        );

        $orderNumber = $odooResult['orderNumber'] ?? 'SP-'.rand(1000, 9999);
        $odooOrderId = $odooResult['odooOrderId'] ?? (string) Str::uuid();
        $totalMinor = $validated['totalMinor'] ?? ($odooResult['totalMinor'] ?? 47500);

        $restaurantId = $validated['restaurantId'] ?? '1';
        $requiresApproval = ! empty($restaurantId);

        $timeoutSec = (int) config('spectre.approval_timeout_sec', 180);
        $expiresAt = $requiresApproval ? Carbon::now()->addSeconds($timeoutSec) : null;
        $initialStatus = $requiresApproval ? 'awaiting_restaurant_approval' : 'pending';

        /** @var Order $order */
        $order = Order::create([
            'id' => (string) Str::uuid(),
            'order_number' => $orderNumber,
            'odoo_order_id' => $odooOrderId,
            'user_id' => $user->id,
            'restaurant_id' => $restaurantId,
            'zone_id' => '1',
            'status' => $initialStatus,
            'total_minor' => $totalMinor,
            'delivery_fee_minor' => 5000,
            'discount_minor' => 0,
            'payment_method' => $validated['paymentMethod'],
            'payment_status' => 'pending',
            'expires_at' => $expiresAt,
            'payload' => [
                'quoteId' => $validated['quoteId'],
                'addressId' => $validated['addressId'],
                'notes' => $validated['notes'] ?? [],
            ],
        ]);

        if ($requiresApproval) {
            // Dispatch 60s reminder
            SendApprovalReminderJob::dispatch($order->id)->delay(now()->addSeconds(60));

            // Dispatch 180s timeout enforcer
            EnforceOrderTimeoutJob::dispatch($order->id)->delay(now()->addSeconds($timeoutSec));

            // Broadcast real-time event to restaurant console
            event(new NewOrderReceived($order));
        }

        return response()->json([
            'data' => [
                'id' => (string) $order->id,
                'orderNumber' => (string) $order->order_number,
                'status' => (string) $order->status,
                'customerStatus' => (string) $order->status,
                'approval' => $requiresApproval ? [
                    'state' => 'pending',
                    'expiresAt' => $order->expires_at?->toIso8601ZuluString(),
                    'timeoutSec' => $timeoutSec,
                ] : null,
                'totalMinor' => (int) $order->total_minor,
                'createdAt' => $order->created_at?->toIso8601ZuluString(),
                'trackable' => false,
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $orders = Order::where('user_id', $user->id)
            ->with('restaurant')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (Order $o): array => [
                'id' => (string) $o->id,
                'orderNumber' => (string) $o->order_number,
                'status' => (string) $o->status,
                'statusLabelAr' => $this->getStatusLabel($o->status, 'ar'),
                'statusLabelEn' => $this->getStatusLabel($o->status, 'en'),
                'isTerminal' => (bool) $o->isTerminal(),
                'isActive' => (bool) $o->isActive(),
                'totalMinor' => (int) $o->total_minor,
                'restaurantName' => $o->restaurant ? $o->restaurant->name_en : 'Spectre Store',
                'createdAt' => $o->created_at?->toIso8601ZuluString(),
                'trackable' => in_array($o->status, ['courier_assigned', 'picked_up', 'on_the_way'], true),
            ]);

        return response()->json([
            'data' => $orders,
            'meta' => [
                'hasMore' => false,
                'nextCursor' => null,
            ],
        ]);
    }

    public function show(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Order|null $order */
        $order = Order::where('id', $id)
            ->where('user_id', $user->id)
            ->with('restaurant')
            ->first();

        if (! $order) {
            // Return 404 not-found (Never 403, do not reveal existence per Spec §1.5)
            return response()->json([
                'error' => [
                    'code' => 'order_not_found',
                    'message' => 'The requested order was not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => (string) $order->id,
                'orderNumber' => (string) $order->order_number,
                'status' => (string) $order->status,
                'statusLabelAr' => $this->getStatusLabel($order->status, 'ar'),
                'statusLabelEn' => $this->getStatusLabel($order->status, 'en'),
                'isTerminal' => (bool) $order->isTerminal(),
                'isActive' => (bool) $order->isActive(),
                'totalMinor' => (int) $order->total_minor,
                'deliveryFeeMinor' => (int) $order->delivery_fee_minor,
                'discountMinor' => (int) $order->discount_minor,
                'payment' => [
                    'method' => (string) $order->payment_method,
                    'status' => (string) $order->payment_status,
                ],
                'restaurant' => [
                    'id' => (string) $order->restaurant_id,
                    'name' => $order->restaurant ? $order->restaurant->name_en : 'Store',
                ],
                'approval' => [
                    'state' => $order->isAwaitingApproval() ? 'pending' : ($order->rejection_reason ? 'rejected' : 'accepted'),
                    'decidedAt' => $order->decided_at?->toIso8601ZuluString(),
                    'rejectionReasonEn' => $order->rejection_reason,
                ],
                'createdAt' => $order->created_at?->toIso8601ZuluString(),
                'trackable' => in_array($order->status, ['courier_assigned', 'picked_up', 'on_the_way'], true),
                'cancellable' => $order->isAwaitingApproval(),
            ],
        ]);
    }

    public function cancel(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Order|null $order */
        $order = Order::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            return response()->json([
                'error' => [
                    'code' => 'order_not_found',
                    'message' => 'The requested order was not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        if ($order->isTerminal()) {
            return response()->json([
                'error' => [
                    'code' => 'already_terminal',
                    'message' => 'Order is already in a terminal state.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 409);
        }

        if (in_array($order->status, ['picked_up', 'on_the_way', 'delivered'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'already_picked_up',
                    'message' => 'Courier has already picked up this order. Please contact support.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 409);
        }

        $order->update([
            'status' => 'cancelled_by_customer',
            'decided_at' => Carbon::now(),
        ]);

        $this->odooClient->cancelOrder($order->odoo_order_id, 'cancelled_by_customer');

        return response()->json([
            'data' => [
                'id' => (string) $order->id,
                'status' => 'cancelled_by_customer',
                'message' => 'Order was successfully cancelled.',
            ],
        ]);
    }

    private function getStatusLabel(string $status, string $locale): string
    {
        $labels = [
            'awaiting_restaurant_approval' => ['ar' => 'بانتظار موافقة المطعم', 'en' => 'Awaiting Restaurant Approval'],
            'preparing' => ['ar' => 'جاري التحضير', 'en' => 'Preparing'],
            'ready_for_pickup' => ['ar' => 'جاهز للاستلام', 'en' => 'Ready for Pickup'],
            'courier_assigned' => ['ar' => 'تم تعيين السائق', 'en' => 'Courier Assigned'],
            'picked_up' => ['ar' => 'تم الاستلام من المطعم', 'en' => 'Picked Up'],
            'on_the_way' => ['ar' => 'في الطريق إليك', 'en' => 'On the Way'],
            'delivered' => ['ar' => 'تم التوصيل', 'en' => 'Delivered'],
            'rejected_by_restaurant' => ['ar' => 'تم الاعتذار عن الطلب', 'en' => 'Rejected by Restaurant'],
            'cancelled_by_customer' => ['ar' => 'ملغي من قبل العميل', 'en' => 'Cancelled by Customer'],
        ];

        return $labels[$status][$locale] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
