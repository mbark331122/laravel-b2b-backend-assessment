<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApprovalRequestPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_REQUEST_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null || $user->isAdmin()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $user, ApprovalRequest $approvalRequest): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_REQUEST_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $approvalRequest->company_id);
    }

    public function decide(User $user, ApprovalRequest $approvalRequest): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_REQUEST_DECIDE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $approvalRequest->company_id);
    }

    public function cancel(User $user, ApprovalRequest $approvalRequest): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_REQUEST_CANCEL)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $approvalRequest->company_id);
    }

    private function sameCompany(User $user, ?int $companyId): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null || $companyId === null || $user->company_id !== $companyId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
