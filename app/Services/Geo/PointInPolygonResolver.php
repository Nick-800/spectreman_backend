<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\Models\SpectreZone;

final class PointInPolygonResolver
{
    /**
     * Resolve delivery coverage and matching zone for coordinates.
     *
     * @return array{deliverable: bool, zone?: SpectreZone, reason?: string, message?: string, nearestZoneName?: string, distanceM?: int}
     */
    public function resolve(float $lat, float $lng): array
    {
        $zones = SpectreZone::where('is_active', true)->with(['area', 'city'])->get();

        if ($zones->isEmpty()) {
            return [
                'deliverable' => false,
                'reason' => 'outside_coverage',
                'message' => 'We do not deliver to this area yet',
                'nearestZoneName' => null,
                'distanceM' => 0,
            ];
        }

        $nearestZone = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($zones as $zone) {
            /** @var array<string, mixed>|null $geojson */
            $geojson = $zone->polygon_geojson;

            if ($geojson && isset($geojson['coordinates'])) {
                /** @var array<int, array<int, array{0: float, 1: float}>> $polygon */
                $polygon = $geojson['coordinates'];
                $ring = $polygon[0] ?? [];

                if ($this->isPointInPolygon($lng, $lat, $ring)) {
                    return [
                        'deliverable' => true,
                        'zone' => $zone,
                    ];
                }

                $center = $this->calculateCentroid($ring);
                $distance = $this->haversineDistanceMeters($lat, $lng, $center[1], $center[0]);

                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $nearestZone = $zone;
                }
            }
        }

        $nearestName = $nearestZone?->name_en ?: ($nearestZone?->area?->name_en ?? 'Benghazi Central');

        return [
            'deliverable' => false,
            'reason' => 'outside_coverage',
            'message' => 'We do not deliver to this area yet',
            'nearestZoneName' => $nearestName,
            'distanceM' => (int) round($minDistance),
        ];
    }

    /**
     * Standard Ray-Casting algorithm for point in polygon.
     *
     * @param  array<int, array{0: float, 1: float}>  $polygonRing
     */
    public function isPointInPolygon(float $x, float $y, array $polygonRing): bool
    {
        $inside = false;
        $count = count($polygonRing);

        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $polygonRing[$i][0];
            $yi = $polygonRing[$i][1];
            $xj = $polygonRing[$j][0];
            $yj = $polygonRing[$j][1];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array{0: float, 1: float} [lng, lat]
     */
    private function calculateCentroid(array $ring): array
    {
        if (empty($ring)) {
            return [20.0686, 32.1149]; // Default to Benghazi center
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        $count = count($ring);

        foreach ($ring as $point) {
            $sumLng += $point[0];
            $sumLat += $point[1];
        }

        return [$sumLng / $count, $sumLat / $count];
    }

    private function haversineDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
