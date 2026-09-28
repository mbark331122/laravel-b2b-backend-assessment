<?php

namespace App\Policies;

use App\Models\IntermediaryCommission;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderParty;
use App\Models\User;
use App\Services\TransactionVisibilityService;
use Illuminate\Auth\Access\Response;

class PurchaseOrderPartyPolicy
{
    public function viewAny(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_READ)) {
            return Response::deny();
        }

        if (! app(TransactionVisibilityService::class)->canAccessTransaction($user, $purchaseOrder)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function view(User $user, PurchaseOrderParty $party): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_READ)) {
            return Response::deny();
        }

        $purchaseOrder = $party->purchaseOrder;
        if ($purchaseOrder === null
            || ! app(TransactionVisibilityService::class)->canAccessTransaction($user, $purchaseOrder)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function manage(User $user, PurchaseOrder $purchaseOrder): Response
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_MANAGE)) {
            return Response::deny();
        }

        if (! app(TransactionVisibilityService::class)->canManageParties($user, $purchaseOrder)) {
            if (app(TransactionVisibilityService::class)->canAccessTransaction($user, $purchaseOrder)) {
                return Response::deny();
            }

            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
