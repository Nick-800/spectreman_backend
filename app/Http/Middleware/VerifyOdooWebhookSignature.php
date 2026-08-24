<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class VerifyOdooWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signatureHeader = (string) $request->header('X-Spectre-Signature', '');
        $secret = (string) config('services.odoo.secret', 'spectreman-internal-odoo-secret');

        $rawBody = (string) $request->getContent();
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        // Accept either "sha256=<hash>" or plain "<hash>"
        $providedHash = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        if (empty($providedHash) || ! hash_equals($expectedSignature, $providedHash)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_webhook_signature',
                    'message' => 'The HMAC signature for the Odoo webhook could not be verified.',
                    'traceId' => (string) Str::uuid(),
                ],
            ], 401);
        }

        return $next($request);
    }
}
