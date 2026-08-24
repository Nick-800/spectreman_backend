<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_number
 * @property string $odoo_order_id
 * @property string $user_id
 * @property string|null $restaurant_id
 * @property string $zone_id
 * @property string $status
 * @property string|null $rejection_reason
 * @property int|null $prep_minutes
 * @property int $total_minor
 * @property int $delivery_fee_minor
 * @property int $discount_minor
 * @property string $payment_method
 * @property string $payment_status
 * @property Carbon|null $expires_at
 * @property Carbon|null $decided_at
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read SpectreRestaurant|null $restaurant
 */
final class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'order_number',
        'odoo_order_id',
        'user_id',
        'restaurant_id',
        'zone_id',
        'status',
        'rejection_reason',
        'prep_minutes',
        'total_minor',
        'delivery_fee_minor',
        'discount_minor',
        'payment_method',
        'payment_status',
        'expires_at',
        'decided_at',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'discount_minor' => 'integer',
            'prep_minutes' => 'integer',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SpectreRestaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(SpectreRestaurant::class, 'restaurant_id');
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === 'awaiting_restaurant_approval';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            'delivered',
            'delivery_failed',
            'rejected_by_restaurant',
            'cancelled_by_customer',
            'cancelled_by_store',
            'refunded',
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
