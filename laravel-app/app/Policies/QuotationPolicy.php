<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QuotationPolicy
{
    public function viewAnyForRfq(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_READ)) {
            return Response::deny();
        }

        return $this->buyerOwnsRfq($user, $rfq);
    }

    public function compare(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_COMPARE)) {
            return Response::deny();
        }

        return $this->buyerOwnsRfq($user, $rfq);
    }

    public function viewForBuyer(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_READ)) {
            return Response::deny();
        }

        $quotation->loadMissing('rfq');

        $owner = $this->buyerOwnsRfq($user, $quotation->rfq);
        if ($owner->denied()) {
            return $owner;
        }

        if ($quotation->status === Quotation::STATUS_DRAFT) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function createForDistribution(User $user, RfqDistribution $distribution): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_CREATE)) {
            return Response::deny();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $distribution->supplier_company_id !== (int) $user->company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function view(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_READ)) {
            return Response::deny();
        }

        return $this->supplierOwnsQuotation($user, $quotation);
    }

    public function update(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_UPDATE)) {
            return Response::deny();
        }

        return $this->supplierOwnsQuotation($user, $quotation);
    }

    public function delete(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_DELETE)) {
            return Response::deny();
        }

        return $this->supplierOwnsQuotation($user, $quotation);
    }

    public function submit(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_SUBMIT)) {
            return Response::deny();
        }

        return $this->supplierOwnsQuotation($user, $quotation);
    }

    public function withdraw(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::QUOTATION_WITHDRAW)) {
            return Response::deny();
        }

        return $this->supplierOwnsQuotation($user, $quotation);
    }

    private function buyerOwnsRfq(User $user, Rfq $rfq): Response
    {
        if ($user->isAdmin() || $user->company_id === $rfq->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    private function supplierOwnsQuotation(User $user, Quotation $quotation): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isSupplierUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $quotation->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
