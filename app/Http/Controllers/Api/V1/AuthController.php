<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Requests\V1\RefreshTokenRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Odoo\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly OdooClient $odooClient,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $normalizedPhone = $request->normalizedPhone();
        $password = (string) $request->input('password');

        $odooAuth = $this->odooClient->verifyCredentials($normalizedPhone, $password);

        if (! $odooAuth['success']) {
            $errorCode = $odooAuth['error'] ?? 'invalid_credentials';
            $statusCode = match ($errorCode) {
                'account_suspended' => 403,
                'account_unverified' => 403,
                default => 401,
            };

            return response()->json([
                'error' => [
                    'code' => $errorCode,
                    'message' => match ($errorCode) {
                        'account_suspended' => 'Your account has been suspended. Please contact support.',
                        'account_unverified' => 'Your account is unverified. Please verify your phone number.',
                        default => 'Invalid phone number or password.',
                    },
                    'traceId' => (string) Str::uuid(),
                ],
            ], $statusCode);
        }

        /** @var User $user */
        $user = User::updateOrCreate(
            ['phone' => $normalizedPhone],
            [
                'odoo_partner_id' => $odooAuth['partnerId'] ?? null,
                'role' => $odooAuth['role'] ?? 'customer',
                'account_state' => $odooAuth['accountState'] ?? 'active',
            ],
        );

        $tokens = $this->jwtService->issueTokenPair($user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $tokens,
            ],
        ]);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $refreshToken = (string) $request->input('refreshToken');
        $result = $this->jwtService->rotateRefreshToken($refreshToken);

        if (! $result) {
            return response()->json([
                'error' => [
                    'code' => 'token_invalid_or_expired',
                    'message' => 'The provided refresh token is invalid, expired, or has already been used.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 401);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => [
                    'tokenType' => $result['tokenType'],
                    'accessToken' => $result['accessToken'],
                    'refreshToken' => $result['refreshToken'],
                    'expiresIn' => $result['expiresIn'],
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->jwtService->revokeAllUserSessions($user->id);

        return response()->json([
            'data' => [
                'message' => 'Logged out successfully.',
            ],
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
