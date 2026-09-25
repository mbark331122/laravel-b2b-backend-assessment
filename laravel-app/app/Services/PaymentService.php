<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaymentService
{
    /**
     * Create a pending payment for an issued invoice (buyer only; amount from invoice).
     */
    public function createForIssuedInvoice(Invoice $invoice, User $actor, string $method, ?string $notes = null): Payment
    {
        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages([
                'method' => 'The selected payment method is invalid.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $actor, $method, $notes) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::STATUS_ISSUED) {
                throw ValidationException::withMessages([
                    'invoice' => 'Payments can only be created for an issued invoice.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->buyer_company_id) {
                throw ValidationException::withMessages([
                    'invoice' => 'Only the buyer company may create a payment for this invoice.',
                ]);
            }

            $paidExists = Payment::query()
                ->where('invoice_id', $locked->id)
                ->where('status', Payment::STATUS_PAID)
                ->lockForUpdate()
                ->exists();

            if ($paidExists) {
                throw ValidationException::withMessages([
                    'invoice' => 'A paid payment already exists for this invoice.',
                ]);
            }

            $active = Payment::query()
                ->where('invoice_id', $locked->id)
                ->where('status', Payment::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw ValidationException::withMessages([
                    'invoice' => 'An active pending payment already exists for this invoice.',
                ]);
            }

            $payment = new Payment;
            $payment->invoice()->associate($locked);
            $payment->buyer_company_id = $locked->buyer_company_id;
            $payment->supplier_company_id = $locked->supplier_company_id;
            $payment->status = Payment::STATUS_PENDING;
            $payment->method = $method;
            $payment->currency = $locked->currency;
            $payment->amount = $locked->total;
            $payment->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $payment->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $payment->save();

            $payment->number = sprintf('PAY-%d-%06d', (int) $payment->created_at->year, (int) $payment->id);
            $payment->active_lock = $locked->id;
            $payment->save();

            return $payment->fresh([
                'invoice',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function markPaid(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo(Payment::STATUS_PAID);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'payment' => $exception->getMessage(),
                ]);
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::STATUS_ISSUED) {
                try {
                    $invoice->transitionTo(Invoice::STATUS_PAID);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'invoice' => $exception->getMessage(),
                    ]);
                }
            } elseif ($invoice->status !== Invoice::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice is not eligible to be marked paid.',
                ]);
            }

            return $locked->fresh([
                'invoice',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }

    public function markFailed(Payment $payment): Payment
    {
        return $this->transition($payment, Payment::STATUS_FAILED);
    }

    public function cancel(Payment $payment): Payment
    {
        return $this->transition($payment, Payment::STATUS_CANCELLED);
    }

    private function transition(Payment $payment, string $to): Payment
    {
        return DB::transaction(function () use ($payment, $to) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'payment' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'invoice',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }
}
