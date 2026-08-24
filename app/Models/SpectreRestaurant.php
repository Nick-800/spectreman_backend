<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name_ar
 * @property string $name_en
 * @property string|null $logo_url
 * @property string|null $cover_url
 * @property bool $is_open
 * @property bool $is_accepting_orders
 * @property string $opens_at
 * @property string $closes_at
 * @property int $avg_prep_minutes
 * @property int|null $delivery_fee_minor
 * @property float $rating
 * @property int $review_count
 * @property string $cuisine_ar
 * @property string $cuisine_en
 * @property string|null $phone
 * @property string|null $address
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SpectreProduct> $products
 */
final class SpectreRestaurant extends Model
{
    protected $table = 'spectre_restaurants';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name_ar',
        'name_en',
        'logo_url',
        'cover_url',
        'is_open',
        'is_accepting_orders',
        'opens_at',
        'closes_at',
        'avg_prep_minutes',
        'delivery_fee_minor',
        'rating',
        'review_count',
        'cuisine_ar',
        'cuisine_en',
        'phone',
        'address',
        'lat',
        'lng',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'is_accepting_orders' => 'boolean',
            'avg_prep_minutes' => 'integer',
            'delivery_fee_minor' => 'integer',
            'rating' => 'float',
            'review_count' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /**
     * @return HasMany<SpectreProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(SpectreProduct::class, 'restaurant_id');
    }

    /**
     * Dynamically compute isOpen in the Africa/Tripoli timezone.
     */
    public function isCurrentlyOpen(): bool
    {
        if (! $this->is_open) {
            return false;
        }

        $now = Carbon::now('Africa/Tripoli');
        $currentTime = $now->format('H:i');

        if ($this->opens_at && $this->closes_at) {
            if ($this->opens_at <= $this->closes_at) {
                return $currentTime >= $this->opens_at && $currentTime <= $this->closes_at;
            }

            // Overnight hours (e.g. 18:00 to 02:00)
            return $currentTime >= $this->opens_at || $currentTime <= $this->closes_at;
        }

        return true;
    }
}
