<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Embed;

use App\Http\Controllers\Controller;
use App\Http\Resources\Embed\EmbedReviewResource;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmbedController extends Controller
{
    public function generateCode(Request $request, Tenant $tenant, ?Location $location = null): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location) {
            if ($location->tenant_id !== $tenant->id) {
                abort(404);
            }

            if (! $location->hasEmbedKey()) {
                $location->generateEmbedKey();
            }

            $embedKey = $location->embed_key;
        } else {
            // For tenant-level embed, use first location or create a tenant-level key
            $location = $tenant->locations()->first();
            
            if (! $location) {
                return response()->json([
                    'message' => 'No locations found for this tenant.',
                ], 422);
            }

            if (! $location->hasEmbedKey()) {
                $location->generateEmbedKey();
            }

            $embedKey = $location->embed_key;
        }

        $theme = $request->query('theme', 'light');
        $layout = $request->query('layout', 'list');

        $embedCode = $this->generateEmbedScript($embedKey, $theme, $layout);
        $previewUrl = url("/embed/preview/{$embedKey}");

        return response()->json([
            'embed_code' => $embedCode,
            'embed_key' => $embedKey,
            'preview_url' => $previewUrl,
        ]);
    }

    public function regenerateKey(Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            abort(404);
        }

        $embedKey = $location->generateEmbedKey();

        $embedCode = $this->generateEmbedScript($embedKey);
        $previewUrl = url("/embed/preview/{$embedKey}");

        return response()->json([
            'embed_code' => $embedCode,
            'embed_key' => $embedKey,
            'preview_url' => $previewUrl,
            'message' => 'Embed key regenerated successfully.',
        ]);
    }

    public function getReviews(Request $request, string $embedKey): JsonResponse
    {
        $location = Location::with('tenant')->where('embed_key', $embedKey)->first();

        if (! $location) {
            abort(404);
        }

        $limit = min((int) $request->query('limit', 10), 50);

        $reviews = $location->reviews()
            ->orderByDesc('rating')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $tenant = $location->tenant;
        $showLogo = ! $tenant->isWhiteLabelEnabled();

        return response()->json([
            'reviews' => EmbedReviewResource::collection($reviews),
            'location' => [
                'name' => $location->name,
            ],
            'branding' => [
                'show_logo' => $showLogo,
                'logo_url' => $showLogo ? url('/images/logo.png') : null,
            ],
        ]);
    }

    protected function generateEmbedScript(string $embedKey, string $theme = 'light', string $layout = 'list'): string
    {
        $baseUrl = url('/');
        
        return <<<HTML
<script src="{$baseUrl}/embed/showcase.js" data-showcase="{$embedKey}" data-theme="{$theme}" data-layout="{$layout}"></script>
HTML;
    }
}
