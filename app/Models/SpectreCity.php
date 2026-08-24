<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SpectreCity extends Model
{
    protected $table = 'spectre_cities';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name_ar',
        'name_en',
    ];

    /**
     * @return HasMany<SpectreArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(SpectreArea::class, 'city_id');
    }
}
