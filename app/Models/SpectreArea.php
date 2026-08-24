<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SpectreArea extends Model
{
    protected $table = 'spectre_areas';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'city_id',
        'name_ar',
        'name_en',
    ];

    /**
     * @return BelongsTo<SpectreCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(SpectreCity::class, 'city_id');
    }

    /**
     * @return HasMany<SpectreZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(SpectreZone::class, 'area_id');
    }
}
