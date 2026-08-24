<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpectreBanner;
use App\Models\SpectreCategory;
use App\Models\SpectreProduct;
use App\Models\SpectreRestaurant;
use App\Services\Search\ArabicTextNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CatalogController extends Controller
{
    public function home(Request $request): Response
    {
        $version = (string) config('spectre.catalog_version', '2026-08-23-01');
        $etag = '"'.md5('home-'.$version).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        $banners = SpectreBanner::orderBy('sort_order')->get()->map(fn ($b) => [
            'id' => (string) $b->id,
            'titleAr' => (string) $b->title_ar,
            'titleEn' => (string) $b->title_en,
            'imageUrl' => (string) $b->image_url,
            'target' => [
                'type' => (string) $b->target_type,
                'id' => (string) $b->target_id,
            ],
        ]);

        $categories = SpectreCategory::orderBy('sort_order')->get()->map(fn ($c) => [
            'id' => (string) $c->id,
            'nameAr' => (string) $c->name_ar,
            'nameEn' => (string) $c->name_en,
            'iconUrl' => (string) $c->icon_url,
        ]);

        $locale = app()->getLocale();
        $trendingProducts = SpectreProduct::where('is_active', true)
            ->where('in_stock', true)
            ->orderByDesc('rating')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id' => (string) $p->id,
                'title' => $locale === 'ar' ? $p->title_ar : $p->title_en,
                'priceMinor' => (int) $p->price_minor,
                'oldPriceMinor' => $p->old_price_minor ? (int) $p->old_price_minor : null,
                'imageUrl' => (string) ($p->image_url ?? ''),
                'rating' => (float) $p->rating,
                'reviewCount' => (int) $p->review_count,
                'unit' => (string) $p->unit,
                'inStock' => (bool) $p->in_stock,
                'restaurantId' => (string) $p->restaurant_id,
            ]);

        return response()->json([
            'data' => [
                'banners' => $banners,
                'categories' => $categories,
                'sections' => [
                    [
                        'key' => 'trending',
                        'titleAr' => 'الأكثر طلباً',
                        'titleEn' => 'Trending',
                        'items' => $trendingProducts,
                    ],
                ],
            ],
        ], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 20), 50);
        $query = SpectreProduct::where('is_active', true);

        if ($q = $request->input('q')) {
            $normalizedQ = ArabicTextNormalizer::normalize((string) $q);
            $query->where(function ($sub) use ($q, $normalizedQ): void {
                $sub->where('title_normalized_ar', 'LIKE', "%{$normalizedQ}%")
                    ->orWhere('title_en', 'LIKE', "%{$q}%");
            });
        }

        if ($categoryId = $request->input('categoryId')) {
            $query->where('category_id', $categoryId);
        }

        if ($restaurantId = $request->input('restaurantId')) {
            $query->where('restaurant_id', $restaurantId);
        }

        match ($request->input('sort')) {
            'price_asc' => $query->orderBy('price_minor', 'asc'),
            'price_desc' => $query->orderBy('price_minor', 'desc'),
            'rating_desc' => $query->orderBy('rating', 'desc'),
            'newest' => $query->orderBy('created_at', 'desc'),
            default => $query->orderBy('rating', 'desc'),
        };

        if ($cursor = $request->input('cursor')) {
            $cursorId = base64_decode((string) $cursor, true) ?: '';
            $query->where('id', '>', $cursorId);
        }

        $products = $query->limit($limit + 1)->get();
        $hasMore = $products->count() > $limit;
        $items = $hasMore ? $products->slice(0, $limit) : $products;

        $lastItem = $items->last();
        $nextCursor = ($hasMore && $lastItem) ? base64_encode((string) $lastItem->id) : null;

        $locale = app()->getLocale();
        $data = $items->map(fn ($p) => [
            'id' => (string) $p->id,
            'title' => $locale === 'ar' ? $p->title_ar : $p->title_en,
            'priceMinor' => (int) $p->price_minor,
            'oldPriceMinor' => $p->old_price_minor ? (int) $p->old_price_minor : null,
            'imageUrl' => (string) ($p->image_url ?? ''),
            'rating' => (float) $p->rating,
            'reviewCount' => (int) $p->review_count,
            'unit' => (string) $p->unit,
            'inStock' => (bool) $p->in_stock,
            'restaurantId' => (string) $p->restaurant_id,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'nextCursor' => $nextCursor,
                'hasMore' => $hasMore,
            ],
        ]);
    }

    public function productDetail(string $id): JsonResponse
    {
        /** @var SpectreProduct|null $product */
        $product = SpectreProduct::with('restaurant')->where('id', $id)->first();

        if (! $product || ! $product->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'product_not_found',
                    'message' => 'The requested product could not be found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        $locale = app()->getLocale();
        /** @var array<string, mixed> $payload */
        $payload = is_array($product->payload) ? $product->payload : [];

        $restaurant = $product->restaurant;
        $restaurantName = $restaurant
            ? ($locale === 'ar' ? $restaurant->name_ar : $restaurant->name_en)
            : '';

        return response()->json([
            'data' => [
                'id' => (string) $product->id,
                'title' => $locale === 'ar' ? $product->title_ar : $product->title_en,
                'description' => $payload['description'] ?? ($locale === 'ar' ? $product->title_ar : $product->title_en),
                'priceMinor' => (int) $product->price_minor,
                'oldPriceMinor' => $product->old_price_minor ? (int) $product->old_price_minor : null,
                'images' => $payload['images'] ?? [$product->image_url],
                'rating' => (float) $product->rating,
                'reviewCount' => (int) $product->review_count,
                'unit' => (string) $product->unit,
                'inStock' => (bool) $product->in_stock,
                'restaurant' => [
                    'id' => $restaurant ? (string) $restaurant->id : (string) $product->restaurant_id,
                    'name' => $restaurantName,
                    'isOpen' => $restaurant ? $restaurant->isCurrentlyOpen() : true,
                ],
                'options' => $payload['options'] ?? [],
                'relatedIds' => $payload['relatedIds'] ?? [],
            ],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $q = (string) $request->input('q', '');
        $normalized = ArabicTextNormalizer::normalize($q);

        $suggestions = [];
        if (mb_strlen($normalized) >= 2) {
            $suggestions = SpectreProduct::where('is_active', true)
                ->where(function ($sub) use ($q, $normalized): void {
                    $sub->where('title_normalized_ar', 'LIKE', "{$normalized}%")
                        ->orWhere('title_en', 'LIKE', "{$q}%");
                })
                ->limit(5)
                ->pluck('title_ar')
                ->all();
        }

        return response()->json([
            'data' => [
                'suggestions' => $suggestions,
                'trending' => ['شاورما', 'برجر دجاج', 'بيتزا'],
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = SpectreCategory::orderBy('sort_order')->get()->map(fn ($c) => [
            'id' => (string) $c->id,
            'nameAr' => (string) $c->name_ar,
            'nameEn' => (string) $c->name_en,
            'iconUrl' => (string) $c->icon_url,
        ]);

        return response()->json(['data' => $categories]);
    }

    public function restaurants(): JsonResponse
    {
        $restaurants = SpectreRestaurant::all()->map(fn ($r) => [
            'id' => (string) $r->id,
            'nameAr' => (string) $r->name_ar,
            'nameEn' => (string) $r->name_en,
            'logoUrl' => (string) ($r->logo_url ?? ''),
            'coverUrl' => (string) ($r->cover_url ?? ''),
            'rating' => (float) $r->rating,
            'reviewCount' => (int) $r->review_count,
            'isOpen' => (bool) $r->isCurrentlyOpen(),
            'opensAt' => (string) $r->opens_at,
            'closesAt' => (string) $r->closes_at,
            'avgPrepMinutes' => (int) $r->avg_prep_minutes,
            'deliveryFeeMinor' => $r->delivery_fee_minor ? (int) $r->delivery_fee_minor : null,
            'cuisineAr' => (string) $r->cuisine_ar,
            'cuisineEn' => (string) $r->cuisine_en,
            'isAcceptingOrders' => (bool) $r->is_accepting_orders,
        ]);

        return response()->json(['data' => $restaurants]);
    }

    public function restaurantDetail(string $id): JsonResponse
    {
        /** @var SpectreRestaurant|null $r */
        $r = SpectreRestaurant::where('id', $id)->first();

        if (! $r) {
            return response()->json([
                'error' => [
                    'code' => 'restaurant_not_found',
                    'message' => 'The requested restaurant was not found.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => (string) $r->id,
                'nameAr' => (string) $r->name_ar,
                'nameEn' => (string) $r->name_en,
                'logoUrl' => (string) ($r->logo_url ?? ''),
                'coverUrl' => (string) ($r->cover_url ?? ''),
                'rating' => (float) $r->rating,
                'reviewCount' => (int) $r->review_count,
                'isOpen' => (bool) $r->isCurrentlyOpen(),
                'opensAt' => (string) $r->opens_at,
                'closesAt' => (string) $r->closes_at,
                'avgPrepMinutes' => (int) $r->avg_prep_minutes,
                'deliveryFeeMinor' => $r->delivery_fee_minor ? (int) $r->delivery_fee_minor : null,
                'cuisineAr' => (string) $r->cuisine_ar,
                'cuisineEn' => (string) $r->cuisine_en,
                'isAcceptingOrders' => (bool) $r->is_accepting_orders,
                'phone' => (string) ($r->phone ?? ''),
                'address' => (string) ($r->address ?? ''),
                'lat' => $r->lat ? (float) $r->lat : null,
                'lng' => $r->lng ? (float) $r->lng : null,
                'description' => (string) ($r->description ?? ''),
            ],
        ]);
    }
}
