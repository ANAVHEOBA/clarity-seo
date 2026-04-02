<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantFromHost
{
    public function __construct(
        private readonly PortalContext $portalContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        $tenant = Tenant::query()
            ->where('custom_domain', $host)
            ->whereNotNull('custom_domain_verified_at')
            ->first();

        $this->portalContext->setTenant($tenant, $host);

        if ($tenant !== null) {
            $this->guardTenantScopedRoutes($request, $tenant);
        }

        return $next($request);
    }

    private function guardTenantScopedRoutes(Request $request, Tenant $tenant): void
    {
        $routeTenant = $request->route('tenant');

        if ($routeTenant instanceof Tenant && $routeTenant->isNot($tenant)) {
            abort(404);
        }

        if (is_numeric($routeTenant) && (int) $routeTenant !== $tenant->id) {
            abort(404);
        }
    }
}
