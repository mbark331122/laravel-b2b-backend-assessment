<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PAYMENT_READ)
            && ($user->isAdmin() || $user->isBuyerUser() || $user->isSupplierUser())
            && ($user->isAdmin() || $user->company_id !== null);
    }

    public function view(User $user, Payment $payment): Response
    {
        if (! $user->hasPermission(Permission::PAYMENT_READ)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id === (int) $payment->buyer_company_id
            || (int) $user->company_id === (int) $payment->supplier_company_id) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    public function createForInvoice(User $user, Invoice $invoice): Response
    {
        if (! $user->hasPermission(Permission::PAYMENT_CREATE)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isBuyerUser() || $user->company_id === null) {
            return Response::deny();
        }

        if ((int) $user->company_id !== (int) $invoice->buyer_company_id) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function markPaid(User $user, Payment $payment): Response
    {
        if (! $user->hasPermission(Permission::PAYMENT_MARK_PAID)) {
            return Response::deny();
        }

        return $this->authorizedActor($user, $payment);
    }

    public function markFailed(User $user, Payment $payment): Response
    {
        if (! $user->hasPermission(Permission::PAYMENT_MARK_FAILED)) {
            return Response::deny();
        }

        return $this->authorizedActor($user, $payment);
    }

    public function cancel(User $user, Payment $payment): Response
    {
        if (! $user->hasPermission(Permission::PAYMENT_CANCEL)) {
            return Response::deny();
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if ($user->isBuyerUser() && (int) $user->company_id === (int) $payment->buyer_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $payment->buyer_company_id
            || (int) $user->company_id === (int) $payment->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }

    private function authorizedActor(User $user, Payment $payment): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        // Supplier confirms receipt (manual bookkeeping); admin also permitted above.
        if ($user->isSupplierUser() && (int) $user->company_id === (int) $payment->supplier_company_id) {
            return Response::allow();
        }

        if ($user->company_id !== null && (
            (int) $user->company_id === (int) $payment->buyer_company_id
            || (int) $user->company_id === (int) $payment->supplier_company_id
        )) {
            return Response::deny();
        }

        return Response::denyAsNotFound();
    }
}
