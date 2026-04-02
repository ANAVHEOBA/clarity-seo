<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\InviteMemberRequest;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Services\Tenant\TenantService;
use App\Support\Portal\PortalContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly PortalContext $portalContext,
    ) {}

    public function store(InviteMemberRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        $role = $request->validated('role');

        // Only owners can invite other owners
        if ($role === 'owner') {
            $this->authorize('inviteOwner', $tenant);
        }

        $invitation = $this->tenantService->inviteMember(
            $tenant,
            $request->validated('email'),
            $role
        );

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
            ],
        ], Response::HTTP_CREATED);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = TenantInvitation::where('token', $token)->firstOrFail();
        $portalTenant = $this->portalContext->tenant();

        if ($portalTenant !== null && $invitation->tenant_id !== $portalTenant->id) {
            abort(404);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'message' => 'Invitation has expired.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $request->user();

        if ($invitation->email !== $user->email) {
            return response()->json([
                'message' => 'This invitation is not for you.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->tenantService->acceptInvitation($invitation, $user);

        return response()->json([
            'message' => 'Invitation accepted successfully.',
            'data' => [
                'tenant_id' => $invitation->tenant_id,
            ],
        ]);
    }
}
