<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RmaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RMA_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $rma->buyer_company_id
            || (int) $user->company_id === (int) $rma->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createForShipment(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::RMA_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isBuyerUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $shipment->buyer_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function cancel(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_CANCEL)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && (int) $user->company_id === (int) $rma->buyer_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $rma->buyer_company_id
            || (int) $user->company_id === (int) $rma->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }

    public function approve(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_APPROVE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $rma);
    }

    public function reject(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_REJECT)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $rma);
    }

    public function receive(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_RECEIVE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $rma);
    }

    public function close(User $user, Rma $rma): Response
    {
        if (! $user->hasPermission(Permission::RMA_CLOSE)) {
            return Response::deny();
        }

        return $this->supplierOwns($user, $rma);
    }

    private function supplierOwns(User $user, Rma $rma): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isSupplierUser() && (int) $user->company_id === (int) $rma->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $rma->buyer_company_id
            || (int) $user->company_id === (int) $rma->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
