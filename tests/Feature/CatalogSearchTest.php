<?php

declare(strict_types=1);

use App\Models\SpectreBanner;
use App\Models\SpectreCategory;
use App\Models\SpectreProduct;
use App\Models\SpectreRestaurant;
use App\Services\Search\ArabicTextNormalizer;

beforeEach(function (): void {
    $restaurant = SpectreRestaurant::create([
        'id' => '1',
        'name_ar' => 'مطعم السلام',
        'name_en' => 'Al Salam',
        'is_open' => true,
        'opens_at' => '08:00',
        'closes_at' => '23:59',
        'delivery_fee_minor' => 5000,
    ]);

    $category = SpectreCategory::create([
        'id' => '3',
        'name_ar' => 'مشاوي',
        'name_en' => 'Grills',
    ]);

    SpectreBanner::create([
        'id' => '1',
        'title_ar' => 'عروض الصيف',
        'title_en' => 'Summer Offers',
        'image_url' => 'https://cdn.spectreman.ly/b/1.webp',
        'target_type' => 'restaurant',
        'target_id' => '1',
    ]);

    // Product 1: Shawarma
    SpectreProduct::create([
        'id' => '12',
        'restaurant_id' => '1',
        'category_id' => '3',
        'title_ar' => 'شاورما لحم',
        'title_en' => 'Beef Shawarma',
        'title_normalized_ar' => ArabicTextNormalizer::normalize('شاورما لحم'),
        'price_minor' => 25000,
        'old_price_minor' => 30000,
        'in_stock' => true,
        'is_active' => true,
        'payload' => [
            'description' => 'Delicious shawarma with spices',
            'options' => [],
            'relatedIds' => [],
        ],
    ]);

    // Product 2: Burger
    SpectreProduct::create([
        'id' => '13',
        'restaurant_id' => '1',
        'category_id' => '3',
        'title_ar' => 'برجر دجاج',
        'title_en' => 'Chicken Burger',
        'title_normalized_ar' => ArabicTextNormalizer::normalize('برجر دجاج'),
        'price_minor' => 20000,
        'in_stock' => true,
        'is_active' => true,
    ]);
});

test('home endpoint returns aggregated feed with ETag', function (): void {
    $response = $this->getJson('/api/v1/home');

    $response->assertOk()
        ->assertHeader('ETag')
        ->assertJsonPath('data.banners.0.titleEn', 'Summer Offers')
        ->assertJsonPath('data.categories.0.nameEn', 'Grills')
        ->assertJsonPath('data.sections.0.key', 'trending')
        ->assertJsonPath('data.sections.0.items.0.priceMinor', 25000);
});

test('products endpoint searches with Arabic orthographic normalization', function (): void {
    // User searches "شاورمة" (Taa Marbuta) -> should match "شاورما" (Alef)
    $response = $this->getJson('/api/v1/products?q='.urlencode('شاورمة'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', '12');

    // User searches with Tashkeel: "شَاوِرْمَا" -> should match
    $tashkeelResponse = $this->getJson('/api/v1/products?q='.urlencode('شَاوِرْمَا'));

    $tashkeelResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', '12');
});

test('product detail returns complete projection in requested language', function (): void {
    // With Arabic Accept-Language
    $responseAr = $this->withHeader('Accept-Language', 'ar')
        ->getJson('/api/v1/products/12');

    $responseAr->assertOk()
        ->assertJsonPath('data.id', '12')
        ->assertJsonPath('data.title', 'شاورما لحم')
        ->assertJsonPath('data.priceMinor', 25000)
        ->assertJsonPath('data.restaurant.name', 'مطعم السلام');

    // With English Accept-Language
    $responseEn = $this->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/products/12');

    $responseEn->assertOk()
        ->assertJsonPath('data.id', '12')
        ->assertJsonPath('data.title', 'Beef Shawarma')
        ->assertJsonPath('data.restaurant.name', 'Al Salam');

    // Non-existent product returns 404
    $notFound = $this->getJson('/api/v1/products/999');
    $notFound->assertStatus(404)
        ->assertJsonPath('error.code', 'product_not_found');
});

test('search suggest returns live completions', function (): void {
    $response = $this->getJson('/api/v1/search/suggest?q='.urlencode('شاو'));

    $response->assertOk()
        ->assertJsonPath('data.suggestions.0', 'شاورما لحم');
});

test('restaurants endpoint returns dynamic restaurant list with isOpen', function (): void {
    $response = $this->getJson('/api/v1/restaurants');

    $response->assertOk()
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.nameEn', 'Al Salam')
        ->assertJsonPath('data.0.isOpen', true);
});
