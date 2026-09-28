<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\Permission;
use App\Models\Rma;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::CREDIT_NOTE_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, CreditNote $creditNote): Response
    {
        if (! $user->hasPermission(Permission::CREDIT_NOTE_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $creditNote->buyer_company_id
            || (int) $user->company_id === (int) $creditNote->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createFromRma(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::CREDIT_NOTE_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $rma->supplier_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function issue(User $user, CreditNote $creditNote): Response
    {
        if (! $user->hasPermission(Permission::CREDIT_NOTE_ISSUE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $creditNote);
    }

    public function cancel(User $user, CreditNote $creditNote): Response
    {
        if (! $user->hasPermission(Permission::CREDIT_NOTE_CANCEL)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $creditNote);
    }

    public function void(User $user, CreditNote $creditNote): Response
    {
        if (! $user->hasPermission(Permission::CREDIT_NOTE_VOID)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $creditNote);
    }

    private function supplierOwns(User $user, CreditNote $creditNote): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $creditNote->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $creditNote->buyer_company_id
            || (int) $user->company_id === (int) $creditNote->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
