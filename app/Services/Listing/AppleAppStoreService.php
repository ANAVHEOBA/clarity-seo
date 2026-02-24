<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\AppleAppStoreAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AppleAppStoreService
{
    public function getApiBaseUrl(): string
    {
        return rtrim((string) config('apple.app_store.api_base_url'), '/');
    }

    public function getJwtTtlMinutes(): int
    {
        return max(1, (int) config('apple.app_store.jwt_ttl_minutes', 20));
    }

    public function getAudience(): string
    {
        return (string) config('apple.app_store.jwt_audience', 'appstoreconnect-v1');
    }

    public function generateJwt(AppleAppStoreAccount $account): string
    {
        $now = time();
        $exp = $now + ($this->getJwtTtlMinutes() * 60);

        $header = [
            'alg' => 'ES256',
            'kid' => $account->key_id,
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $account->issuer_id,
            'iat' => $now,
            'exp' => $exp,
            'aud' => $this->getAudience(),
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $privateKey = openssl_pkey_get_private((string) $account->private_key);
        if (! $privateKey) {
            throw new RuntimeException('Invalid private key configured for this account.');
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_pkey_free($privateKey);

        if (! $ok) {
            throw new RuntimeException('Unable to sign App Store JWT with the provided private key.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /** @return array{valid: bool, reason: string|null, jwt: string|null} */
    public function validateAccountForApi(AppleAppStoreAccount $account): array
    {
        if (! $account->is_active) {
            return ['valid' => false, 'reason' => 'Account is inactive.', 'jwt' => null];
        }

        try {
            $jwt = $this->generateJwt($account);
        } catch (\Throwable $e) {
            return ['valid' => false, 'reason' => $e->getMessage(), 'jwt' => null];
        }

        return ['valid' => true, 'reason' => null, 'jwt' => $jwt];
    }

    /** @return array{ok: bool, status: int|null, body: array<string, mixed>|null, message: string} */
    public function pingAppsEndpoint(AppleAppStoreAccount $account): array
    {
        $tokenResult = $this->validateAccountForApi($account);
        if (! $tokenResult['valid'] || empty($tokenResult['jwt'])) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'message' => (string) ($tokenResult['reason'] ?? 'Unable to generate JWT.'),
            ];
        }

        $response = Http::withToken((string) $tokenResult['jwt'])
            ->acceptJson()
            ->get($this->getApiBaseUrl().'/v1/apps', [
                'limit' => 1,
            ]);

        $body = is_array($response->json()) ? $response->json() : null;

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $body,
            'message' => $response->successful()
                ? 'Successfully reached App Store Connect API.'
                : 'App Store Connect API rejected the request.',
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
