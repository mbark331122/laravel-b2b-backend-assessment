<?php

namespace App\Policies;

use App\Models\CompanyContact;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyContactPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_CONTACT_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, CompanyContact $contact): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_CONTACT_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $contact->company_id);
    }

    public function create(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_CONTACT_MANAGE)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, CompanyContact $contact): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_CONTACT_MANAGE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $contact->company_id);
    }

    public function delete(User $user, CompanyContact $contact): Response
    {
        return $this->update($user, $contact);
    }

    private function sameCompany(User $user, ?int $companyId): Response
    {
        if ($user->company_id === null || $companyId === null || $user->company_id !== $companyId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
