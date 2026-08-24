<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $restaurant_id
 * @property string $category_id
 * @property string $title_ar
 * @property string $title_en
 * @property string $title_normalized_ar
 * @property int $price_minor
 * @property int|null $old_price_minor
 * @property string|null $image_url
 * @property string $unit
 * @property float $rating
 * @property int $review_count
 * @property bool $in_stock
 * @property bool $is_active
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SpectreRestaurant|null $restaurant
 * @property-read SpectreCategory|null $category
 */
final class SpectreProduct extends Model
{
    protected $table = 'spectre_products';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'restaurant_id',
        'category_id',
        'title_ar',
        'title_en',
        'title_normalized_ar',
        'price_minor',
        'old_price_minor',
        'image_url',
        'unit',
        'rating',
        'review_count',
        'in_stock',
        'is_active',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'old_price_minor' => 'integer',
            'rating' => 'float',
            'review_count' => 'integer',
            'in_stock' => 'boolean',
            'is_active' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SpectreRestaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(SpectreRestaurant::class, 'restaurant_id');
    }

    /**
     * @return BelongsTo<SpectreCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SpectreCategory::class, 'category_id');
    }
}
