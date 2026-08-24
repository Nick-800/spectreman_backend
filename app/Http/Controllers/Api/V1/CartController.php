<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpectreProduct;
use App\Models\SpectreRestaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class CartController extends Controller
{
    public function validateCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'zoneId' => ['required', 'string'],
        ]);

        /** @var array<int, array{productId: string, qty: int}> $items */
        $items = $validated['items'];
        $productIds = array_column($items, 'productId');
        $products = SpectreProduct::with('restaurant')->whereIn('id', $productIds)->get()->keyBy('id');

        $restaurantIds = $products->pluck('restaurant_id')->unique()->filter()->values();

        // 1. Single vendor check
        if ($restaurantIds->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'cart_vendor_conflict',
                    'message' => 'Items in cart must all be from the same restaurant.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 409);
        }

        $changes = [];
        $validatedItems = [];
        $status = 'ok';

        $firstRestaurantId = (string) ($restaurantIds->first() ?? '');
        /** @var SpectreRestaurant|null $restaurant */
        $restaurant = $firstRestaurantId !== '' ? SpectreRestaurant::find($firstRestaurantId) : null;

        if ($restaurant && ! $restaurant->isCurrentlyOpen()) {
            $status = 'blocked';
            $changes[] = [
                'type' => 'restaurant_closed',
                'productId' => null,
                'messageAr' => 'المطعم مغلق حالياً',
                'messageEn' => 'Restaurant is currently closed.',
            ];
        }

        foreach ($items as $item) {
            /** @var SpectreProduct|null $product */
            $product = $products->get($item['productId']);

            if (! $product || ! $product->is_active) {
                $status = $status === 'blocked' ? 'blocked' : 'changed';
                $changes[] = [
                    'type' => 'product_unavailable',
                    'productId' => $item['productId'],
                    'messageAr' => 'المنتج لم يعد متوفراً',
                    'messageEn' => 'Product is no longer available.',
                ];

                continue;
            }

            if (! $product->in_stock) {
                $status = $status === 'blocked' ? 'blocked' : 'changed';
                $changes[] = [
                    'type' => 'out_of_stock',
                    'productId' => $product->id,
                    'messageAr' => "نفذت الكمية من {$product->title_ar}",
                    'messageEn' => "{$product->title_en} is out of stock.",
                ];

                continue;
            }

            $validatedItems[] = [
                'productId' => (string) $product->id,
                'qty' => (int) $item['qty'],
                'available' => true,
                'unitPriceMinor' => (int) $product->price_minor,
                'lineTotalMinor' => (int) ($product->price_minor * $item['qty']),
            ];
        }

        return response()->json([
            'data' => [
                'status' => $status,
                'items' => $validatedItems,
                'changes' => $changes,
            ],
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.productId' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'addressId' => ['required', 'string'],
            'couponCode' => ['nullable', 'string'],
            'paymentMethod' => ['required', 'string'],
        ]);

        /** @var array<int, array{productId: string, qty: int}> $items */
        $items = $validated['items'];
        $productIds = array_column($items, 'productId');
        $products = SpectreProduct::whereIn('id', $productIds)->get();
        $restaurantIds = $products->pluck('restaurant_id')->unique()->filter()->values();

        if ($restaurantIds->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'cart_vendor_conflict',
                    'message' => 'Items in cart must all be from the same restaurant.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 409);
        }

        $quoteId = 'q_'.Str::random(8);
        $expiresAt = Carbon::now()->addMinutes(10)->toIso8601ZuluString();

        $subtotal = 0;
        foreach ($items as $item) {
            $p = $products->firstWhere('id', $item['productId']);
            if ($p instanceof SpectreProduct) {
                $subtotal += ($p->price_minor * $item['qty']);
            }
        }

        $firstRestaurantId = (string) ($restaurantIds->first() ?? '');
        /** @var SpectreRestaurant|null $restaurant */
        $restaurant = $firstRestaurantId !== '' ? SpectreRestaurant::find($firstRestaurantId) : null;
        $deliveryFee = (int) ($restaurant?->delivery_fee_minor ?? 5000);
        $discount = 0;

        if (! empty($validated['couponCode'])) {
            if (strtoupper((string) $validated['couponCode']) === 'SPECTRE15') {
                $discount = (int) round($subtotal * 0.15);
            }
        }

        $totalMinor = max(0, $subtotal + $deliveryFee - $discount);
        $requiresApproval = $restaurantIds->isNotEmpty();

        $secret = (string) config('app.key');
        $signature = hash_hmac('sha256', "{$quoteId}:{$totalMinor}:{$expiresAt}", $secret);

        $breakdown = [
            ['key' => 'subtotal', 'labelAr' => 'المجموع الفرعي', 'labelEn' => 'Subtotal', 'amountMinor' => $subtotal],
            ['key' => 'delivery_fee', 'labelAr' => 'رسوم التوصيل', 'labelEn' => 'Delivery', 'amountMinor' => $deliveryFee],
        ];

        if ($discount > 0) {
            $breakdown[] = ['key' => 'discount', 'labelAr' => 'الخصم', 'labelEn' => 'Discount', 'amountMinor' => -$discount];
        }

        return response()->json([
            'data' => [
                'quoteId' => $quoteId,
                'signature' => $signature,
                'expiresAt' => $expiresAt,
                'breakdown' => $breakdown,
                'totalMinor' => $totalMinor,
                'currency' => 'LYD',
                'coupon' => [
                    'code' => $validated['couponCode'] ?? null,
                    'valid' => $discount > 0,
                    'discountMinor' => $discount,
                ],
                'requiresRestaurantApproval' => $requiresApproval,
                'restaurantId' => $restaurantIds->first() ?? null,
                'changes' => [],
            ],
        ]);
    }
}
