<?php

namespace App\Policies;

use App\Models\DeliveryConfirmation;
use App\Models\Permission;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeliveryConfirmationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::DELIVERY_CONFIRMATION_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, DeliveryConfirmation $confirmation): Response
    {
        if (! $user->hasPermission(Permission::DELIVERY_CONFIRMATION_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $confirmation->buyer_company_id
            || (int) $user->company_id === (int) $confirmation->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createForShipment(User $user, Shipment $shipment): Response
    {
        if (! $user->hasPermission(Permission::DELIVERY_CONFIRMATION_CREATE)) {
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
}
