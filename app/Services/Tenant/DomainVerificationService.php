<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DomainVerificationService
{
    public const VERIFICATION_PATH = '/.well-known/localmator-domain-verification.txt';

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function status(Tenant $tenant, array $context = []): array
    {
        $this->ensureCustomDomainIsPresent($tenant);

        return [
            'custom_domain' => $tenant->custom_domain,
            'is_verified' => $tenant->hasVerifiedCustomDomain(),
            'custom_domain_verified_at' => $tenant->custom_domain_verified_at?->toIso8601String(),
            'domain_verification_requested_at' => $tenant->domain_verification_requested_at?->toIso8601String(),
            'verification_path' => self::VERIFICATION_PATH,
            'verification_token' => $tenant->domain_verification_token,
            'verification_target' => $this->publicVerificationUrl(
                $tenant,
                $this->preferredPublicScheme($tenant, $context)
            ),
            'local_testing_target' => $this->localTestingTarget($tenant, $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function issueChallenge(Tenant $tenant, array $context = []): array
    {
        $this->ensureCustomDomainIsPresent($tenant);

        $tenant->forceFill([
            'custom_domain_verified_at' => null,
            'domain_verification_token' => Str::random(48),
            'domain_verification_requested_at' => now(),
        ])->save();

        return $this->status($tenant->fresh(), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function verify(Tenant $tenant, array $context = []): array
    {
        $this->ensureChallengeCanBeVerified($tenant);

        [$url, $headers] = $this->verificationAttempt($tenant, $context);

        try {
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->accept('text/plain')
                ->get($url);
        } catch (ConnectionException $exception) {
            return [
                'verified' => false,
                'message' => 'Could not reach the domain. Please check DNS or hosting setup.',
                'verification_target' => $url,
                'error' => $exception->getMessage(),
            ];
        }

        $body = trim($response->body());

        if (! $response->successful()) {
            return [
                'verified' => false,
                'message' => 'Verification request failed.',
                'verification_target' => $url,
                'status_code' => $response->status(),
            ];
        }

        if ($body !== $tenant->domain_verification_token) {
            return [
                'verified' => false,
                'message' => 'Verification token did not match the expected value.',
                'verification_target' => $url,
                'status_code' => $response->status(),
            ];
        }

        $tenant->forceFill([
            'custom_domain_verified_at' => now(),
            'domain_verification_token' => null,
        ])->save();

        return [
            'verified' => true,
            'message' => 'Custom domain verified successfully.',
            'verification_target' => $url,
            'status_code' => $response->status(),
            'data' => $this->status($tenant->fresh(), $context),
        ];
    }

    public function clear(Tenant $tenant): Tenant
    {
        $tenant->forceFill([
            'custom_domain_verified_at' => null,
            'domain_verification_token' => null,
            'domain_verification_requested_at' => null,
        ])->save();

        return $tenant->fresh();
    }

    private function ensureCustomDomainIsPresent(Tenant $tenant): void
    {
        if (empty($tenant->custom_domain)) {
            throw new RuntimeException('Set a custom_domain on the tenant before requesting verification.');
        }
    }

    private function ensureChallengeCanBeVerified(Tenant $tenant): void
    {
        $this->ensureCustomDomainIsPresent($tenant);

        if (empty($tenant->domain_verification_token)) {
            throw new RuntimeException('Generate a domain verification challenge before attempting verification.');
        }
    }

    private function publicVerificationUrl(Tenant $tenant, ?string $preferredScheme = null): string
    {
        $scheme = in_array($preferredScheme, ['http', 'https'], true)
            ? $preferredScheme
            : (app()->environment(['local', 'testing']) ? 'http' : 'https');

        return $scheme.'://'.$tenant->custom_domain.self::VERIFICATION_PATH;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function localTestingTarget(Tenant $tenant, array $context): ?array
    {
        if (! $this->shouldUseLocalProxy($tenant, $context)) {
            return null;
        }

        $baseHost = $context['request_host'] ?? null;
        $basePort = $context['request_port'] ?? null;
        $baseScheme = $context['request_scheme'] ?? 'http';

        if (! $baseHost) {
            return null;
        }

        $portSuffix = $basePort ? ':'.$basePort : '';

        return [
            'proxy_url' => $baseScheme.'://'.$baseHost.$portSuffix.self::VERIFICATION_PATH,
            'host_header' => $tenant->custom_domain,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: array<string, string>}
     */
    private function verificationAttempt(Tenant $tenant, array $context): array
    {
        if ($this->shouldUseLocalProxy($tenant, $context)) {
            $usesExplicitVerificationHost = is_string($context['verification_host'] ?? null)
                && ($context['verification_host'] ?? '') !== '';

            $baseHost = $usesExplicitVerificationHost
                ? $context['verification_host']
                : ($context['request_host'] ?? null);
            $basePort = $usesExplicitVerificationHost
                ? ($context['verification_port'] ?? null)
                : ($context['verification_port'] ?? $context['request_port'] ?? null);
            $baseScheme = $context['verification_scheme'] ?? $context['request_scheme'] ?? 'http';

            if ($baseHost) {
                $portSuffix = $basePort ? ':'.$basePort : '';

                return [
                    $baseScheme.'://'.$baseHost.$portSuffix.self::VERIFICATION_PATH,
                    ['Host' => $tenant->custom_domain],
                ];
            }
        }

        return [
            $this->publicVerificationUrl($tenant, $this->preferredPublicScheme($tenant, $context)),
            [],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function preferredPublicScheme(Tenant $tenant, array $context): ?string
    {
        $requestHost = $context['request_host'] ?? null;

        if (! is_string($requestHost) || strcasecmp($requestHost, (string) $tenant->custom_domain) !== 0) {
            return null;
        }

        $scheme = $context['verification_scheme'] ?? $context['request_scheme'] ?? null;

        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldUseLocalProxy(Tenant $tenant, array $context): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        $verificationHost = $context['verification_host'] ?? null;

        if (is_string($verificationHost) && $verificationHost !== '') {
            return true;
        }

        $requestHost = $context['request_host'] ?? null;

        if (! is_string($requestHost) || $requestHost === '') {
            return false;
        }

        return strcasecmp($requestHost, (string) $tenant->custom_domain) !== 0;
    }
}
