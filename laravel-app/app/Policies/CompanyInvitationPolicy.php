<?php

namespace App\Policies;

use App\Models\CompanyInvitation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyInvitationPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_INVITATION_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, CompanyInvitation $invitation): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_INVITATION_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $invitation->company_id);
    }

    public function create(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_INVITATION_CREATE)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function revoke(User $user, CompanyInvitation $invitation): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_INVITATION_MANAGE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $invitation->company_id);
    }

    private function sameCompany(User $user, ?int $companyId): Response
    {
        if ($user->company_id === null || $companyId === null || $user->company_id !== $companyId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
