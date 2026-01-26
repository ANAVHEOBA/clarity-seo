<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutomationWorkflow;
use App\Models\User;

class AutomationWorkflowPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }

    /**
     * Determine whether the user can execute the workflow.
     */
    public function execute(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AutomationWorkflow $workflow): bool
    {
        return $user->tenants()->where('tenant_id', $workflow->tenant_id)->exists();
    }
}