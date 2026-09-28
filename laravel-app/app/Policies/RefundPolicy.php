<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::REFUND_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, Refund $refund): Response
    {
        if (! $user->hasPermission(Permission::REFUND_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $refund->buyer_company_id
            || (int) $user->company_id === (int) $refund->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createFromCreditNote(User $user, CreditNote $creditNote): Response
    {
        if (! $user->hasPermission(Permission::REFUND_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $creditNote->supplier_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function process(User $user, Refund $refund): Response
    {
        if (! $user->hasPermission(Permission::REFUND_PROCESS)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $refund);
    }

    public function fail(User $user, Refund $refund): Response
    {
        if (! $user->hasPermission(Permission::REFUND_FAIL)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $refund);
    }

    public function cancel(User $user, Refund $refund): Response
    {
        if (! $user->hasPermission(Permission::REFUND_CANCEL)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $refund);
    }

    private function supplierOwns(User $user, Refund $refund): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $refund->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $refund->buyer_company_id
            || (int) $user->company_id === (int) $refund->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
