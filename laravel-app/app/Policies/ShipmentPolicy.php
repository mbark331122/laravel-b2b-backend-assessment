<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::SHIPMENT_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $shipment->buyer_company_id
            || (int) $user->company_id === (int) $shipment->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createFromPurchaseOrder(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_CREATE)) {
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

    public function update(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_UPDATE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $shipment);
    }

    public function process(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_PROCESS)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $shipment);
    }

    public function ship(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_SHIP)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $shipment);
    }

    public function deliver(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_DELIVER)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $shipment);
    }

    public function cancel(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::SHIPMENT_CANCEL)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $shipment);
    }

    private function supplierOwns(User $user, Shipment $shipment): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $shipment->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $shipment->buyer_company_id
            || (int) $user->company_id === (int) $shipment->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
