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
            && $user->company_id !== null
            && $user->isBuyerUser();
    }

    public function update(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_UPDATE)) {
            return Response::deny();
        }

        $tenant = $this->tenantResponse($user, $rfq);
        if ($tenant->denied()) {
            return $tenant;
        }

        if (! $rfq->isEditable() && ! $user->isAdmin()) {
            return Response::deny('Only draft RFQs can be updated.');
        }

        return Response::allow();
    }

    public function delete(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_DELETE)) {
            return Response::deny();
        }

        $tenant = $this->tenantResponse($user, $rfq);
        if ($tenant->denied()) {
            return $tenant;
        }

        if (! $rfq->isDeletable()) {
            return Response::deny('Only draft RFQs can be deleted. Cancel or close submitted RFQs instead.');
        }

        return Response::allow();
    }

    public function submit(User $user, Rfq $rfq): Response
    {
        return $this->transitionAbility($user, $rfq, Permission::RFQ_SUBMIT);
    }

    public function cancel(User $user, Rfq $rfq): Response
    {
        return $this->transitionAbility($user, $rfq, Permission::RFQ_CANCEL);
    }

    public function close(User $user, Rfq $rfq): Response
    {
        return $this->transitionAbility($user, $rfq, Permission::RFQ_CLOSE);
    }

    public function approve(User $user, Rfq $rfq): Response
    {
        if (! $user->hasPermission(Permission::RFQ_APPROVE)) {
            return Response::deny();
        }

        return $this->tenantResponse($user, $rfq);
    }

    private function transitionAbility(User $user, Rfq $rfq, string $permission): Response
    {
        if (! $user->hasPermission($permission)) {
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
