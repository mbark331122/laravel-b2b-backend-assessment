<?php

namespace App\Policies;

use App\Models\Negotiation;
use App\Models\NegotiationOffer;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NegotiationPolicy
{
    public function viewAnyForRfq(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_READ)) {
            return Response::deny();
        }

        return $this->buyerOwnsRfq($user, $rfq);
    }

    public function viewAnyForSupplier(User $user): bool
    {
        return $user->hasPermission(Permission::NEGOTIATION_READ)
            && $user->isSupplierUser()
            && $user->company_id !== null;
    }

    public function view(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_READ)) {
            return Response::deny();
        }

        return $this->participantResponse($user, $negotiation);
    }

    public function createForQuotation(User $user, Quotation $quotation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_CREATE)) {
            return Response::deny();
        }

        $quotation->loadMissing('rfq');

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        $isBuyer = $user->isBuyerUser() && (int) $user->company_id === (int) $quotation->rfq->company_id;
        $isSupplier = $user->isSupplierUser() && (int) $user->company_id === (int) $quotation->supplier_company_id;

        if ($isBuyer || $isSupplier) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createOffer(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_OFFER_CREATE)) {
            return Response::deny();
        }

        return $this->participantResponse($user, $negotiation);
    }

    public function acceptOffer(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_OFFER_ACCEPT)) {
            return Response::deny();
        }

        return $this->participantResponse($user, $negotiation);
    }

    public function reject(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_REJECT)) {
            return Response::deny();
        }

        return $this->participantResponse($user, $negotiation);
    }

    public function withdraw(User $user, Negotiation $negotiation): Response
    {
        if (! $user->hasPermission(Permission::NEGOTIATION_WITHDRAW)) {
            return Response::deny();
        }

        return $this->participantResponse($user, $negotiation);
    }

    private function buyerOwnsRfq(User $user, Rfq $rfq): Response
    {
        if ($user->isAdmin() || $user->company_id === $rfq->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    private function participantResponse(User $user, Negotiation $negotiation): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($negotiation->isParticipant($user)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
