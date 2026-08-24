<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnforceJsonResponse;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnforceJsonResponse::class,
            SetLocaleFromHeader::class,
        ]);
        $middleware->alias([
            'role' => RequireRole::class,
            'idempotent' => Idempotency::class,
            'auth.jwt' => AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $fieldErrors = [];
                foreach ($e->errors() as $key => $messages) {
                    $fieldErrors[$key] = $messages[0] ?? 'Invalid value';
                }

                return response()->json([
                    'error' => [
                        'code' => 'validation_error',
                        'message' => $e->getMessage(),
                        'fieldErrors' => $fieldErrors,
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'unauthorized',
                    403 => 'forbidden',
                    404 => 'not_found',
                    409 => 'conflict',
                    410 => 'expired',
                    422 => 'unprocessable_entity',
                    429 => 'rate_limit_exceeded',
                    default => 'http_error',
                };

                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                        'traceId' => (string) Str::uuid(),
                    ],
                ], $status);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'internal_server_error',
                        'message' => app()->isProduction() ? 'An unexpected server error occurred.' : $e->getMessage(),
                        'traceId' => (string) Str::uuid(),
                    ],
                ], 500);
            }
        });
    })->create();
