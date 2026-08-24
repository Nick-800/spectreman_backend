<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class Idempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return response()->json([
                'error' => [
                    'code' => 'missing_idempotency_key',
                    'message' => 'Idempotency-Key header is required for this operation.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 400);
        }

        $user = $request->user();
        $userId = $user ? (string) $user->id : 'anonymous';
        $cacheKey = "idempotency:{$userId}:{$idempotencyKey}";
        $lockKey = "lock:{$cacheKey}";

        $payloadHash = hash('sha256', (string) $request->getContent());

        /** @var array{hash: string, status: int, headers: array<string, array<string>>, content: string}|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if ($cached['hash'] !== $payloadHash) {
                return response()->json([
                    'error' => [
                        'code' => 'idempotency_payload_mismatch',
                        'message' => 'Idempotency key was previously used with a different request payload.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 422);
            }

            return response($cached['content'], $cached['status'], $cached['headers']);
        }

        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return response()->json([
                'error' => [
                    'code' => 'concurrent_request_in_flight',
                    'message' => 'A request with this idempotency key is currently being processed.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 409);
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'hash' => $payloadHash,
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->all(),
                    'content' => $response->getContent() ?: '',
                ], now()->addHours(24));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
