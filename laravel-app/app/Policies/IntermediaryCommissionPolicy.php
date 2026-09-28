<?php

namespace App\Policies;

use App\Models\IntermediaryCommission;
use App\Models\Permission;
use App\Models\User;
use App\Services\TransactionVisibilityService;
use Illuminate\Auth\Access\Response;

class IntermediaryCommissionPolicy
{
    public function view(User $user, IntermediaryCommission $commission): Response
    {
        // Missing permission must not confirm commission existence (IDOR/enumeration).
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_COMMISSION_READ)
            || ! app(TransactionVisibilityService::class)->canViewCommission($user, $commission)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }
}
