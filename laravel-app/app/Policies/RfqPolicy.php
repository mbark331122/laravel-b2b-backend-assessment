<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RfqPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RFQ_READ);
    }

    public function view(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_READ)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $rfq);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::RFQ_CREATE)
            && $user->company_id !== null;
    }

    public function update(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_UPDATE)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $rfq);
    }

    public function approve(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_APPROVE)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $rfq);
    }

    private function tenantResponse(User $user, Rfq $rfq): Response
    {
        if ($user->isAdmin() || $user->company_id === $rfq->company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
