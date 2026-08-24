<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class JwtService
{
    private string $secret;

    private int $accessTtl = 1800; // 30 minutes

    private int $refreshTtlDays = 30; // 30 days

    public function __construct()
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }
        $this->secret = $appKey ?: 'spectreman-default-secret-key-replace-in-production';
    }

    /**
     * @param  array<string, mixed>  $customClaims
     */
    public function generateAccessToken(User $user, array $customClaims = []): string
    {
        $now = Carbon::now()->getTimestamp();
        $jti = (string) Str::uuid();

        $payload = array_merge([
            'iss' => config('app.url', 'https://spectreman.ly'),
            'sub' => $user->id,
            'role' => $user->role,
            'partnerId' => $user->odoo_partner_id,
            'phone' => $user->phone,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
        ], $customClaims);

        return $this->encode($payload);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, tokenType: string}
     */
    public function issueTokenPair(User $user): array
    {
        $accessToken = $this->generateAccessToken($user);
        $rawRefreshToken = Str::random(64);
        $tokenHash = hash('sha256', $rawRefreshToken);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'revoked' => false,
            'expires_at' => Carbon::now()->addDays($this->refreshTtlDays),
        ]);

        return [
            'tokenType' => 'Bearer',
            'accessToken' => $accessToken,
            'refreshToken' => $rawRefreshToken,
            'expiresIn' => $this->accessTtl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateAccessToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signature = $this->base64UrlDecode($signatureB64);
        $expectedSignature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true);

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadB64);
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < Carbon::now()->timestamp) {
            return null;
        }

        if (isset($payload['jti']) && Cache::has("denylist:jti:{$payload['jti']}")) {
            return null;
        }

        if (isset($payload['sub']) && Cache::has("denylist:user:{$payload['sub']}")) {
            return null;
        }

        return $payload;
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, tokenType: string, user: User}|null
     */
    public function rotateRefreshToken(string $rawRefreshToken): ?array
    {
        $tokenHash = hash('sha256', $rawRefreshToken);

        /** @var RefreshToken|null $record */
        $record = RefreshToken::where('token_hash', $tokenHash)->with('user')->first();

        if (! $record) {
            return null;
        }

        // Reuse detection: If token was already revoked, purge all tokens for this user
        if ($record->revoked) {
            RefreshToken::where('user_id', $record->user_id)->update(['revoked' => true]);
            Cache::put("denylist:user:{$record->user_id}", true, now()->addDays($this->refreshTtlDays));

            return null;
        }

        if ($record->isExpired()) {
            $record->update(['revoked' => true]);

            return null;
        }

        // Revoke the used token
        $record->update(['revoked' => true]);

        $user = $record->user;
        if (! $user || ! $user->isActive()) {
            return null;
        }

        $pair = $this->issueTokenPair($user);

        return array_merge($pair, ['user' => $user]);
    }

    public function revokeSession(string $rawRefreshToken): bool
    {
        $tokenHash = hash('sha256', $rawRefreshToken);
        $updated = RefreshToken::where('token_hash', $tokenHash)->update(['revoked' => true]);

        return $updated > 0;
    }

    public function revokeAllUserSessions(string $userId): void
    {
        RefreshToken::where('user_id', $userId)->update(['revoked' => true]);
        Cache::put("denylist:user:{$userId}", true, now()->addDays($this->refreshTtlDays));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $headerB64 = $this->base64UrlEncode((string) json_encode($header));
        $payloadB64 = $this->base64UrlEncode((string) json_encode($payload));

        $signature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true);
        $signatureB64 = $this->base64UrlEncode($signature);

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
