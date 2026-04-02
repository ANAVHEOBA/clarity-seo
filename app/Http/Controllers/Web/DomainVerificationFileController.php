<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DomainVerificationFileController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $tenant = Tenant::query()
            ->where('custom_domain', strtolower($request->getHost()))
            ->whereNotNull('domain_verification_token')
            ->firstOrFail();

        return response($tenant->domain_verification_token, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
