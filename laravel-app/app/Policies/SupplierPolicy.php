<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SupplierPolicy
{
    public function viewBank(User $user, Supplier $supplier): Response
    {
        if (! $user->hasPermission(Permission::BANK_READ)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $supplier);
    }

    public function requestBankChange(User $user, Supplier $supplier): Response
    {
        if (! $user->hasPermission(Permission::BANK_CHANGE_REQUEST)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $supplier);
    }

    public function approveBankChange(User $user, Supplier $supplier): Response
    {
        if (! $user->hasPermission(Permission::BANK_APPROVE)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $supplier);
    }

    private function tenantResponse(User $user, Supplier $supplier): Response
    {
        if ($user->isAdmin() || $user->company_id === $supplier->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
