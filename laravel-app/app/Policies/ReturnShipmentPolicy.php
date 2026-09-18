<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\ReturnShipment;
use App\Models\Rma;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReturnShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RETURN_SHIPMENT_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, ReturnShipment $returnShipment): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $returnShipment->buyer_company_id
            || (int) $user->company_id === (int) $returnShipment->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createForRma(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isBuyerUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $rma->buyer_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, ReturnShipment $returnShipment): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_UPDATE)) {
            return Response::deny();
        }

        return $this->buyerOwns($user, $returnShipment);
    }

    public function cancel(User $user, ReturnShipment $returnShipment): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_CANCEL)) {
            return Response::deny();
        }

        return $this->buyerOwns($user, $returnShipment);
    }

    public function ship(User $user, ReturnShipment $returnShipment): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_SHIP)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $returnShipment);
    }

    public function deliver(User $user, ReturnShipment $returnShipment): Response
    {
        if (! $user->hasPermission(Permission::RETURN_SHIPMENT_DELIVER)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $returnShipment);
    }

    private function buyerOwns(User $user, ReturnShipment $returnShipment): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && (int) $user->company_id === (int) $returnShipment->buyer_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $returnShipment->buyer_company_id
            || (int) $user->company_id === (int) $returnShipment->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }

    private function supplierOwns(User $user, ReturnShipment $returnShipment): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $returnShipment->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $returnShipment->buyer_company_id
            || (int) $user->company_id === (int) $returnShipment->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
