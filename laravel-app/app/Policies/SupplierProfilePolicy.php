<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\SupplierProfile;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SupplierProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::SUPPLIER_PROFILE_READ);
    }

    public function view(User $user, SupplierProfile $supplierProfile): Response
    {
        if (! $user->hasPermission(Permission::SUPPLIER_PROFILE_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin() || $user->company_id === $supplierProfile->company_id) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && $supplierProfile->status === SupplierProfile::STATUS_ACTIVE) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::SUPPLIER_PROFILE_CREATE)
            && $user->isSupplierUser()
            && $user->company_id !== null
            && $user->company?->supplierProfile === null;
    }

    public function update(User $user, SupplierProfile $supplierProfile): Response
    {
        if (! $user->hasPermission(Permission::SUPPLIER_PROFILE_UPDATE)) {
            return Response::deny();
        }

        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            return Response::deny();
        }

        if ($user->isAdmin() || $user->company_id === $supplierProfile->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
