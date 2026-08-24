<?php

declare(strict_types=1);

use App\Models\SpectreArea;
use App\Models\SpectreCity;
use App\Models\SpectreZone;

beforeEach(function (): void {
    SpectreCity::create(['id' => '1', 'name_ar' => 'بنغازي', 'name_en' => 'Benghazi']);
    SpectreArea::create(['id' => '7', 'city_id' => '1', 'name_ar' => 'الفويهات', 'name_en' => 'Fuwayhat']);

    // Create a zone in Fuwayhat, Benghazi with a polygon bounding box
    SpectreZone::create([
        'id' => '3',
        'city_id' => '1',
        'area_id' => '7',
        'warehouse_id' => '1',
        'name_ar' => 'الفويهات الغربية',
        'name_en' => 'Fuwayhat West',
        'delivery_fee_minor' => 5000,
        'min_order_minor' => 20000,
        'eta_minutes' => 35,
        'is_active' => true,
        'polygon_geojson' => [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [20.0600, 32.1100],
                    [20.0800, 32.1100],
                    [20.0800, 32.1300],
                    [20.0600, 32.1300],
                    [20.0600, 32.1100],
                ],
            ],
        ],
    ]);
});

test('config endpoint returns business tunables with ETag support', function (): void {
    $response = $this->getJson('/api/v1/config');

    $response->assertOk()
        ->assertHeader('ETag')
        ->assertJsonPath('data.currency.code', 'LYD')
        ->assertJsonPath('data.currency.exponent', 3)
        ->assertJsonPath('data.approval.timeoutSec', 180)
        ->assertJsonPath('data.approval.reminderSec', 60)
        ->assertJsonPath('data.delivery.defaultFeeMinor', 5000);

    $etag = $response->headers->get('ETag');

    // Conditional request with If-None-Match should return 304
    $conditional = $this->withHeader('If-None-Match', $etag)
        ->get('/api/v1/config');

    $conditional->assertStatus(304);
});

test('geo ref-data returns bundle without leaking internal polygons', function (): void {
    $response = $this->getJson('/api/v1/geo/ref-data');

    $response->assertOk()
        ->assertHeader('ETag')
        ->assertJsonPath('data.cities.0.nameEn', 'Benghazi')
        ->assertJsonPath('data.areas.0.nameEn', 'Fuwayhat')
        ->assertJsonPath('data.zones.0.deliveryFeeMinor', 5000)
        ->assertJsonMissingPath('data.zones.0.polygon_geojson');
});

test('delivery check returns deliverable true when coordinates are inside zone polygon', function (): void {
    // 32.1200, 20.0700 is inside the Fuwayhat box
    $response = $this->postJson('/api/v1/delivery/check', [
        'lat' => 32.1200,
        'lng' => 20.0700,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.deliverable', true)
        ->assertJsonPath('data.zoneId', '3')
        ->assertJsonPath('data.warehouseId', '1')
        ->assertJsonPath('data.deliveryFeeMinor', 5000);
});

test('delivery check returns 200 deliverable false when coordinates are outside coverage', function (): void {
    // Point far away (e.g. Cairo or Mediterranean Sea)
    $response = $this->postJson('/api/v1/delivery/check', [
        'lat' => 30.0000,
        'lng' => 31.0000,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.deliverable', false)
        ->assertJsonPath('data.reason', 'outside_coverage')
        ->assertJsonStructure([
            'data' => ['deliverable', 'reason', 'message', 'nearestZoneName', 'distanceM'],
        ]);
});

test('reverse geocode returns formatted address bundle', function (): void {
    $response = $this->getJson('/api/v1/geocode/reverse?lat=32.1200&lng=20.0700');

    $response->assertOk()
        ->assertJsonPath('data.cityId', '1')
        ->assertJsonPath('data.areaId', '7')
        ->assertJsonStructure([
            'data' => ['address', 'cityId', 'areaId'],
        ]);
});
