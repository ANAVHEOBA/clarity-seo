<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;

class TenantService
{
    public function create(array $data, User $owner): Tenant
    {
        $data = $this->normalizeTenantData($data);

        return DB::transaction(function () use ($data, $owner) {
            $tenant = Tenant::create($data);
            $tenant->users()->attach($owner->id, ['role' => 'owner']);
            $owner->update(['current_tenant_id' => $tenant->id]);

            return $tenant;
        });
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $data = $this->normalizeTenantData($data, $tenant);
        $tenant->update($data);

        return $tenant->fresh();
    }

    public function delete(Tenant $tenant): void
    {
        $tenant->delete();
    }

    public function inviteMember(Tenant $tenant, string $email, string $role): TenantInvitation
    {
        $invitation = $tenant->invitations()->create([
            'email' => $email,
            'role' => $role,
        ]);

        // Send notification
        // Notification::route('mail', $email)->notify(new TenantInvitationNotification($invitation));

        return $invitation;
    }

    public function acceptInvitation(TenantInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $invitation->tenant->users()->attach($user->id, ['role' => $invitation->role]);

            if (! $user->current_tenant_id) {
                $user->update(['current_tenant_id' => $invitation->tenant_id]);
            }

            $invitation->delete();
        });
    }

    public function removeMember(Tenant $tenant, User $user): void
    {
        $tenant->users()->detach($user->id);

        if ($user->current_tenant_id === $tenant->id) {
            $user->update(['current_tenant_id' => $user->tenants()->first()?->id]);
        }
    }

    public function updateMemberRole(Tenant $tenant, User $user, string $role): void
    {
        $tenant->users()->updateExistingPivot($user->id, ['role' => $role]);
    }

    public function leaveTenant(Tenant $tenant, User $user): void
    {
        $this->removeMember($tenant, $user);
    }

    public function switchTenant(User $user, Tenant $tenant): void
    {
        $user->switchTenant($tenant);
    }

    public function joinTenantAsMember(Tenant $tenant, User $user): void
    {
        $tenant->users()->syncWithoutDetaching([
            $user->id => ['role' => 'member'],
        ]);

        $user->update(['current_tenant_id' => $tenant->id]);
    }

    private function normalizeTenantData(array $data, ?Tenant $tenant = null): array
    {
        if (array_key_exists('custom_domain', $data)) {
            $data['custom_domain'] = $data['custom_domain']
                ? strtolower(trim((string) $data['custom_domain']))
                : null;
        }

        if (array_key_exists('hide_vendor_branding', $data)) {
            $data['white_label_enabled'] = (bool) $data['hide_vendor_branding'];
        }

        if (array_key_exists('support_email', $data) && empty($data['reply_to_email'] ?? null)) {
            $data['reply_to_email'] = $data['support_email'];
        }

        if (! app()->environment(['local', 'testing'])) {
            unset($data['custom_domain_verified_at']);
        }

        if (
            $tenant !== null
            && array_key_exists('custom_domain', $data)
            && $tenant->custom_domain !== $data['custom_domain']
            && ! array_key_exists('custom_domain_verified_at', $data)
        ) {
            $data['custom_domain_verified_at'] = null;
        }

        if (empty($data['custom_domain'] ?? null)) {
            $data['custom_domain_verified_at'] = null;
        }

        return $data;
    }
}
