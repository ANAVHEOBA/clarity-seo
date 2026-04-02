<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Tenant;

class PortalContext
{
    private ?Tenant $tenant = null;

    private ?string $host = null;

    public function setTenant(?Tenant $tenant, ?string $host = null): void
    {
        $this->tenant = $tenant;
        $this->host = $host;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}
