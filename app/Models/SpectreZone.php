<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $city_id
 * @property string $area_id
 * @property string $warehouse_id
 * @property string $name_ar
 * @property string $name_en
 * @property int $delivery_fee_minor
 * @property int $min_order_minor
 * @property int $eta_minutes
 * @property bool $is_active
 * @property array<string, mixed>|null $polygon_geojson
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SpectreArea|null $area
 * @property-read SpectreCity|null $city
 */
final class SpectreZone extends Model
{
    protected $table = 'spectre_zones';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'city_id',
        'area_id',
        'warehouse_id',
        'name_ar',
        'name_en',
        'delivery_fee_minor',
        'min_order_minor',
        'eta_minutes',
        'is_active',
        'polygon_geojson',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_fee_minor' => 'integer',
            'min_order_minor' => 'integer',
            'eta_minutes' => 'integer',
            'is_active' => 'boolean',
            'polygon_geojson' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SpectreArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(SpectreArea::class, 'area_id');
    }

    /**
     * @return BelongsTo<SpectreCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(SpectreCity::class, 'city_id');
    }
}
