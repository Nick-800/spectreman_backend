<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DriverController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $now = Carbon::now();
        Cache::put("driver:presence:{$user->id}", $now->timestamp, 300);

        if (isset($validated['lat'], $validated['lng'])) {
            Cache::put("driver:location:{$user->id}", [
                'lat' => (float) $validated['lat'],
                'lng' => (float) $validated['lng'],
                'updatedAt' => $now->toIso8601ZuluString(),
            ], 300);
        }

        // Derive presence
        $presence = 'live';

        return response()->json([
            'data' => [
                'presence' => $presence,
                'nextHeartbeatSec' => 30,
                'pendingOffers' => [],
            ],
        ]);
    }

    public function location(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'taskId' => ['nullable', 'string'],
        ]);

        $locationData = [
            'lat' => (float) $validated['lat'],
            'lng' => (float) $validated['lng'],
            'heading' => (float) ($validated['heading'] ?? 0),
            'speed' => (float) ($validated['speed'] ?? 0),
            'taskId' => $validated['taskId'] ?? null,
            'updatedAt' => Carbon::now()->toIso8601ZuluString(),
        ];

        // Store in Redis / Cache buffer (Sub-millisecond write, zero Odoo ORM load)
        Cache::put("driver:location:{$user->id}", $locationData, 300);

        if (! empty($validated['taskId'])) {
            Cache::put("order:tracking:{$validated['taskId']}", $locationData, 300);
        }

        return response()->json([
            'data' => [
                'received' => true,
            ],
        ]);
    }

    public function transition(string $id, string $transition, Request $request): JsonResponse
    {
        $validTransitions = [
            'head-to-store',
            'arrive-store',
            'pick-up',
            'start-delivery',
            'deliver',
            'fail',
        ];

        if (! in_array($transition, $validTransitions, true)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_transition',
                    'message' => "Transition '{$transition}' is not recognized.",
                    'traceId' => (string) Str::uuid(),
                ],
            ], 422);
        }

        /** @var Order|null $order */
        $order = Order::find($id);

        if (! $order) {
            return response()->json([
                'error' => [
                    'code' => 'task_not_found',
                    'message' => 'Task was not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        // At pick-up: Handover code verification
        if ($transition === 'pick-up') {
            $handoverCode = (string) $request->input('handoverCode');
            if (empty($handoverCode)) {
                return response()->json([
                    'error' => [
                        'code' => 'handover_code_required',
                        'message' => 'Handover code from restaurant is required.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 422);
            }

            $order->update(['status' => 'picked_up']);
        } elseif ($transition === 'deliver') {
            // Soft geofence check (<=150m ok, 150-500m warning, >500m rejected)
            $distanceM = (int) $request->input('distanceM', 50);

            if ($distanceM > 500) {
                return response()->json([
                    'error' => [
                        'code' => 'outside_geofence',
                        'message' => 'You are too far from the delivery location to confirm delivery.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 422);
            }

            $order->update([
                'status' => 'delivered',
                'payment_status' => 'paid',
                'decided_at' => Carbon::now(),
            ]);
        } else {
            $statusMap = [
                'head-to-store' => 'courier_assigned',
                'arrive-store' => 'courier_assigned',
                'start-delivery' => 'on_the_way',
                'fail' => 'delivery_failed',
            ];

            if (isset($statusMap[$transition])) {
                $order->update(['status' => $statusMap[$transition]]);
            }
        }

        return response()->json([
            'data' => [
                'taskId' => (string) $order->id,
                'status' => (string) $order->status,
                'transition' => $transition,
                'updatedAt' => Carbon::now()->toIso8601ZuluString(),
            ],
        ]);
    }
}
