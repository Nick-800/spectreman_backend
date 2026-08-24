<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'odooPartnerId' => (string) $this->odoo_partner_id,
            'phone' => (string) $this->phone,
            'name' => $this->name,
            'email' => $this->email,
            'role' => (string) $this->role,
            'accountState' => (string) $this->account_state,
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
