<?php

namespace App\Policies;

use App\Models\CompanyProfile;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyProfilePolicy
{
    public function viewOwn(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_PROFILE_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, CompanyProfile $profile): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_PROFILE_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $profile->company_id);
    }

    public function upsert(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_PROFILE_UPDATE)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, CompanyProfile $profile): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_PROFILE_UPDATE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $profile->company_id);
    }

    private function sameCompany(User $user, ?int $companyId): Response
    {
        if ($user->company_id === null || $companyId === null || $user->company_id !== $companyId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
