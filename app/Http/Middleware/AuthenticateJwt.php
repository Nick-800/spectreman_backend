<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwtService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Authorization bearer token is missing.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 401);
        }

        $claims = $this->jwtService->validateAccessToken($token);

        if (! $claims || ! isset($claims['sub'])) {
            return response()->json([
                'error' => [
                    'code' => 'token_invalid_or_expired',
                    'message' => 'The provided access token is invalid or has expired.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 401);
        }

        /** @var User|null $user */
        $user = User::find($claims['sub']);

        if (! $user || ! $user->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'account_suspended_or_not_found',
                    'message' => 'The user account is suspended or no longer exists.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 403);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
