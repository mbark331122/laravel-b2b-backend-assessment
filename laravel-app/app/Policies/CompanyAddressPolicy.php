<?php

namespace App\Policies;

use App\Models\CompanyAddress;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyAddressPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_ADDRESS_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, CompanyAddress $address): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_ADDRESS_READ)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $address->company_id);
    }

    public function create(User $user): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_ADDRESS_MANAGE)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, CompanyAddress $address): Response
    {
        if (! $user->hasPermission(Permission::COMPANY_ADDRESS_MANAGE)) {
            return Response::deny();
        }

        return $this->sameCompany($user, $address->company_id);
    }

    public function delete(User $user, CompanyAddress $address): Response
    {
        return $this->update($user, $address);
    }

    private function sameCompany(User $user, ?int $companyId): Response
    {
        if ($user->company_id === null || $companyId === null || $user->company_id !== $companyId) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
