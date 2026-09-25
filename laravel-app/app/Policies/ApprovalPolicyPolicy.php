<?php

namespace App\Policies;

use App\Models\ApprovalPolicy;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApprovalPolicyPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_POLICY_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null || $user->isAdmin()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $user, ApprovalPolicy $policy): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_POLICY_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $policy->company_id);
    }

    public function create(User $user): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_POLICY_MANAGE)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, ApprovalPolicy $policy): Response
    {
        if (! $user->hasPermission(Permission::APPROVAL_POLICY_MANAGE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $policy->company_id);
    }

    public function manage(User $user, ApprovalPolicy $policy): Response
    {
        return $this->update($user, $policy);
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
