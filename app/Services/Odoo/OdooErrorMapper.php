<?php

declare(strict_types=1);

namespace App\Services\Odoo;

final class OdooErrorMapper
{
    /**
     * Map an Odoo internal error code to a standardized public API error code.
     */
    public static function map(string $odooCode, int $httpStatus = 400): string
    {
        return match ($odooCode) {
            'odoo_invalid_credentials', 'user_not_found', 'wrong_password' => 'invalid_credentials',
            'user_suspended', 'partner_suspended' => 'account_suspended',
            'user_unverified', 'partner_unverified' => 'account_unverified',
            'quote_not_found', 'quote_invalid' => 'quote_expired',
            'quote_consumed' => 'quote_already_used',
            'insufficient_balance' => 'wallet_insufficient_funds',
            'coupon_invalid' => 'coupon_not_found',
            'coupon_exhausted' => 'coupon_usage_limit_reached',
            'coupon_expired' => 'coupon_expired',
            'vendor_conflict' => 'cart_vendor_conflict',
            'restaurant_unavailable' => 'restaurant_closed',
            'already_resolved' => 'already_decided',
            'deadline_passed' => 'approval_expired',
            default => match ($httpStatus) {
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                410 => 'expired',
                422 => 'unprocessable_entity',
                429 => 'rate_limit_exceeded',
                default => 'odoo_service_error',
            },
        };
    }
}
