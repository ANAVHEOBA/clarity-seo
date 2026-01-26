<?php

namespace App\Providers;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Policies\AutomationWorkflowPolicy;
use App\Policies\TenantPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Tenant::class => TenantPolicy::class,
        AutomationWorkflow::class => AutomationWorkflowPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}