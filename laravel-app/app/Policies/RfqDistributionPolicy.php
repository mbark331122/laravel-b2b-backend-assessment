<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RfqDistributionPolicy
{
    public function viewAny(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_DISTRIBUTION_READ)) {
            return Response::deny();
        }

        return $this->buyerOwnsRfq($user, $rfq);
    }

    public function match(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_DISTRIBUTION_READ)) {
            return Response::deny();
        }

        return $this->buyerOwnsRfq($user, $rfq);
    }

    public function create(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_DISTRIBUTION_CREATE)) {
            return Response::deny();
        }

        $owner = $this->buyerOwnsRfq($user, $rfq);
        if ($owner->denied()) {
            return $owner;
        }

        if (! $user->isAdmin() && ! $user->isBuyerUser()) {
            return Response::deny();
        }

        return Response::allow();
    }

    public function withdraw(User $user, RfqDistribution $distribution): Response
    {
        if (! $user->hasPermission(Permission::RFQ_DISTRIBUTION_WITHDRAW)) {
            return Response::deny();
        }

        $distribution->loadMissing('rfq');

        return $this->buyerOwnsRfq($user, $distribution->rfq);
    }

    public function viewDistributed(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::SUPPLIER_RFQ_READ)) {
            return Response::deny();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        $hasActiveDistribution = $rfq->distributions()
            ->active()
            ->where('supplier_company_id', $user->company_id)
            ->exists();

        if ($hasActiveDistribution) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function viewAnyDistributed(User $user): bool
    {
        return $user->hasPermission(Permission::SUPPLIER_RFQ_READ)
            && $user->isSupplierUser()
            && $user->company_id !== null;
    }

    private function buyerOwnsRfq(User $user, Rfq $rfq): Response
    {
        if ($user->isAdmin() || $user->company_id === $rfq->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
