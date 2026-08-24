<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SpectreArea;
use App\Models\SpectreCity;
use App\Models\SpectreZone;
use App\Services\Geo\PointInPolygonResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GeoController extends Controller
{
    public function __construct(
        private readonly PointInPolygonResolver $pipResolver,
    ) {}

    public function refData(Request $request): Response
    {
        $version = (string) config('spectre.ref_data_version', '2026-08-01-01');
        $etag = '"'.md5($version).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        $cities = SpectreCity::all(['id', 'name_ar', 'name_en'])->map(fn ($c) => [
            'id' => (string) $c->id,
            'nameAr' => (string) $c->name_ar,
            'nameEn' => (string) $c->name_en,
        ]);

        $areas = SpectreArea::all(['id', 'city_id', 'name_ar', 'name_en'])->map(fn ($a) => [
            'id' => (string) $a->id,
            'cityId' => (string) $a->city_id,
            'nameAr' => (string) $a->name_ar,
            'nameEn' => (string) $a->name_en,
        ]);

        $zones = SpectreZone::where('is_active', true)->get()->map(fn ($z) => [
            'id' => (string) $z->id,
            'cityId' => (string) $z->city_id,
            'areaId' => (string) $z->area_id,
            'warehouseId' => (string) $z->warehouse_id,
            'nameAr' => (string) $z->name_ar,
            'nameEn' => (string) $z->name_en,
            'deliveryFeeMinor' => (int) $z->delivery_fee_minor,
            'minOrderMinor' => (int) $z->min_order_minor,
            'etaMinutes' => (int) $z->eta_minutes,
            'isActive' => (bool) $z->is_active,
        ]);

        return response()->json([
            'data' => [
                'version' => $version,
                'cities' => $cities,
                'areas' => $areas,
                'zones' => $zones,
            ],
        ], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function checkDelivery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $result = $this->pipResolver->resolve($lat, $lng);

        if ($result['deliverable'] && isset($result['zone'])) {
            $zone = $result['zone'];

            return response()->json([
                'data' => [
                    'deliverable' => true,
                    'zoneId' => (string) $zone->id,
                    'zoneName' => (string) ($zone->name_en ?: $zone->area?->name_en ?: 'Benghazi Zone'),
                    'warehouseId' => (string) $zone->warehouse_id,
                    'deliveryFeeMinor' => (int) $zone->delivery_fee_minor,
                    'minOrderMinor' => (int) $zone->min_order_minor,
                    'etaMinutes' => (int) $zone->eta_minutes,
                ],
            ]);
        }

        // Return 200 OK with deliverable: false (Never 4xx for out-of-coverage)
        return response()->json([
            'data' => [
                'deliverable' => false,
                'reason' => 'outside_coverage',
                'message' => 'We do not deliver to this area yet',
                'nearestZoneName' => $result['nearestZoneName'] ?? 'Benghazi Central',
                'distanceM' => $result['distanceM'] ?? 0,
            ],
        ]);
    }

    public function reverseGeocode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $result = $this->pipResolver->resolve($lat, $lng);
        $zone = $result['zone'] ?? null;

        $address = $zone instanceof SpectreZone
            ? ($zone->area ? $zone->area->name_en : 'Benghazi').', Benghazi'
            : 'Benghazi, Libya';

        return response()->json([
            'data' => [
                'address' => $address,
                'cityId' => $zone instanceof SpectreZone ? (string) $zone->city_id : '1',
                'areaId' => $zone instanceof SpectreZone ? (string) $zone->area_id : '1',
            ],
        ]);
    }
}
