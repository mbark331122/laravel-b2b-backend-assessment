<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::INVOICE_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, Invoice $invoice): Response
    {
        if (! $user->hasPermission(Permission::INVOICE_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $invoice->buyer_company_id
            || (int) $user->company_id === (int) $invoice->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createFromPurchaseOrder(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::INVOICE_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $purchaseOrder->supplier_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function issue(User $user, Invoice $invoice): Response
    {
        if (! $user->hasPermission(Permission::INVOICE_ISSUE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $invoice);
    }

    public function cancel(User $user, Invoice $invoice): Response
    {
        if (! $user->hasPermission(Permission::INVOICE_CANCEL)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $invoice);
    }

    public function void(User $user, Invoice $invoice): Response
    {
        if (! $user->hasPermission(Permission::INVOICE_VOID)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $invoice);
    }

    private function supplierOwns(User $user, Invoice $invoice): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $invoice->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $invoice->buyer_company_id
            || (int) $user->company_id === (int) $invoice->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
