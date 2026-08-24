<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TrackingController extends Controller
{
    public function show(string $id): JsonResponse
    {
        /** @var Order|null $order */
        $order = Order::with('restaurant')->find($id);

        if (! $order) {
            return response()->json([
                'error' => [
                    'code' => 'order_not_found',
                    'message' => 'Order not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        /** @var array{lat: float, lng: float, heading: float, speed: float, updatedAt: string}|null $location */
        $location = Cache::get("order:tracking:{$id}") ?? [
            'lat' => 32.1160,
            'lng' => 20.0700,
            'heading' => 45.0,
            'updatedAt' => now()->toIso8601ZuluString(),
        ];

        return response()->json([
            'data' => [
                'trackable' => true,
                'status' => (string) $order->status,
                'statusLabelEn' => ucfirst(str_replace('_', ' ', $order->status)),
                'etaAt' => now()->addMinutes(15)->toIso8601ZuluString(),
                'courier' => [
                    'name' => 'Ali',
                    'phoneMasked' => '+218900000000',
                    'vehicle' => 'motorcycle',
                    'rating' => 4.8,
                    'lat' => $location['lat'],
                    'lng' => $location['lng'],
                    'heading' => $location['heading'] ?? 0,
                    'lastSeenAt' => $location['updatedAt'],
                ],
                'customer' => [
                    'lat' => 32.1149,
                    'lng' => 20.0686,
                ],
                'route' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
            ],
        ]);
    }

    public function stream(string $id): StreamedResponse
    {
        return new StreamedResponse(function () use ($id): void {
            /** @var array{lat: float, lng: float, heading: float, updatedAt: string}|null $location */
            $location = Cache::get("order:tracking:{$id}") ?? [
                'lat' => 32.1160,
                'lng' => 20.0700,
                'heading' => 45.0,
                'updatedAt' => now()->toIso8601ZuluString(),
            ];

            $eventData = json_encode([
                'status' => 'on_the_way',
                'lat' => $location['lat'],
                'lng' => $location['lng'],
                'heading' => $location['heading'] ?? 0,
                'etaMinutes' => 12,
            ]);

            echo "event: location_update\n";
            echo "data: {$eventData}\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
