<?php

namespace App\Policies;

use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PURCHASE_ORDER_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && (int) $user->company_id === (int) $purchaseOrder->buyer_company_id) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $purchaseOrder->supplier_company_id) {
            // Suppliers only see POs after buyer submission.
            if ($purchaseOrder->status === PurchaseOrder::STATUS_DRAFT) {
                return Response::denyAsNotFound();
            }

            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createFromNegotiation(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isBuyerUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $negotiation->buyer_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_SUBMIT)) {
            return Response::deny();
        }

        return $this->buyerOwns($user, $purchaseOrder);
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_CANCEL)) {
            return Response::deny();
        }

        return $this->buyerOwns($user, $purchaseOrder);
    }

    public function complete(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_COMPLETE)) {
            return Response::deny();
        }

        return $this->buyerOwns($user, $purchaseOrder);
    }

    public function confirm(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_CONFIRM)) {
            return Response::deny();
        }

        // Draft POs are not visible to suppliers yet.
        if ($purchaseOrder->status === PurchaseOrder::STATUS_DRAFT) {
            return Response::denyAsNotFound();
        }

        return $this->supplierOwns($user, $purchaseOrder);
    }

    public function reject(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_REJECT)) {
            return Response::deny();
        }

        if ($purchaseOrder->status === PurchaseOrder::STATUS_DRAFT) {
            return Response::denyAsNotFound();
        }

        return $this->supplierOwns($user, $purchaseOrder);
    }

    private function buyerOwns(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && (int) $user->company_id === (int) $purchaseOrder->buyer_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $purchaseOrder->buyer_company_id
            || (int) $user->company_id === (int) $purchaseOrder->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }

    private function supplierOwns(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $purchaseOrder->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $purchaseOrder->buyer_company_id
            || (int) $user->company_id === (int) $purchaseOrder->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }

    private function partyResponse(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $purchaseOrder->buyer_company_id
            || (int) $user->company_id === (int) $purchaseOrder->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
