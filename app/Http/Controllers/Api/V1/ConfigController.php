<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfigController extends Controller
{
    public function show(Request $request): Response
    {
        $configVersion = (string) config('spectre.config_version', '2026-08-23-01');
        $etag = '"'.md5($configVersion).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        $configData = [
            'configVersion' => $configVersion,
            'currency' => [
                'code' => 'LYD',
                'symbol' => 'LYD',
                'exponent' => 3,
            ],
            'refDataVersion' => (string) config('spectre.ref_data_version', '2026-08-01-01'),
            'catalogVersion' => (string) config('spectre.catalog_version', '2026-08-23-01'),
            'app' => [
                'minSupportedVersion' => '1.0.0',
                'forceUpdate' => false,
                'storeUrlAndroid' => '',
                'storeUrlIos' => '',
            ],
            'maintenance' => [
                'enabled' => false,
                'message' => null,
            ],
            'otp' => [
                'length' => 4,
                'ttlSec' => 300,
                'resendCooldownSec' => 60,
                'maxAttempts' => 5,
            ],
            'order' => [
                'minOrderMinor' => 0,
                'cancelWindowSec' => 120,
            ],
            'approval' => [
                'timeoutSec' => 180,
                'reminderSec' => 60,
            ],
            'delivery' => [
                'defaultFeeMinor' => 5000,
            ],
            'tracking' => [
                'customerPollSec' => 8,
                'driverPingSec' => 8,
                'driverMinDistanceM' => 40,
                'driverBatchSize' => 12,
            ],
            'cash' => [
                'floatCeilingMinor' => 500000,
            ],
            'support' => [
                'phone' => '+218910000000',
                'whatsapp' => '+218910000000',
            ],
            'features' => [
                'courierChat' => false,
                'walletTopup' => true,
            ],
        ];

        return response()->json(['data' => $configData], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
