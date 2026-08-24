<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SpectreCategory extends Model
{
    protected $table = 'spectre_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name_ar',
        'name_en',
        'icon_url',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<SpectreProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(SpectreProduct::class, 'category_id');
    }
}
