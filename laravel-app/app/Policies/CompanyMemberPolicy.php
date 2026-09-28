<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorizes company member administration against User records in the actor's company.
 */
class CompanyMemberPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_MEMBER_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $actor, User $member): Response
    {
        if (! $actor->hasPermission(Permission::COMPANY_MEMBER_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($actor, $member);
    }

    public function manage(User $actor, User $member): Response
    {
        if (! $actor->hasPermission(Permission::COMPANY_MEMBER_MANAGE)) {
            return Response::deny();
        }

        return $this->sameCompany($actor, $member);
    }

    private function sameCompany(User $actor, User $member): Response
    {
        if ($actor->company_id === null || $member->company_id === null || $actor->company_id !== $member->company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
