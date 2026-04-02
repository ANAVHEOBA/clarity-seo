<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function __construct(
        private readonly PortalContext $portalContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->portalContext->tenant();

        if ($tenant === null) {
            return response()->json([
                'data' => [
                    'host' => $request->getHost(),
                    'is_branded_portal' => false,
                    'tenant' => null,
                    'branding' => [
                        'brand_name' => config('app.name'),
                        'logo_url' => null,
                        'favicon_url' => null,
                        'primary_color' => null,
                        'secondary_color' => null,
                        'support_email' => config('mail.from.address'),
                        'hide_vendor_branding' => false,
                    ],
                    'auth' => [
                        'public_signup_enabled' => true,
                        'invite_only' => false,
                    ],
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'host' => $request->getHost(),
                'is_branded_portal' => true,
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'custom_domain' => $tenant->custom_domain,
                    'custom_domain_verified_at' => $tenant->custom_domain_verified_at?->toIso8601String(),
                ],
                'branding' => [
                    'brand_name' => $tenant->brandDisplayName(),
                    'logo_url' => $tenant->resolvedLogoUrl(),
                    'favicon_url' => $tenant->favicon_url,
                    'primary_color' => $tenant->primary_color,
                    'secondary_color' => $tenant->secondary_color,
                    'support_email' => $tenant->support_email,
                    'reply_to_email' => $tenant->reply_to_email,
                    'hide_vendor_branding' => $tenant->isWhiteLabelEnabled(),
                ],
                'auth' => [
                    'public_signup_enabled' => (bool) $tenant->public_signup_enabled,
                    'invite_only' => ! $tenant->public_signup_enabled,
                ],
            ],
        ]);
    }
}
