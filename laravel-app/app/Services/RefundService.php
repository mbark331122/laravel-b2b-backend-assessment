<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RefundService
{
    /**
     * Create a pending internal refund record from an issued credit note (idempotent).
     *
     * @return array{refund: Refund, created: bool}
     */
    public function createFromIssuedCreditNote(
        CreditNote $creditNote,
        User $actor,
        ?string $method = null,
        ?string $reason = null,
    ): array {
        return DB::transaction(function () use ($creditNote, $actor, $method, $reason) {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->whereKey($creditNote->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['invoice', 'rma']);

            if ($locked->status !== CreditNote::STATUS_ISSUED) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Refunds can only be created from an issued credit note.',
                ]);
            }

            if ((int) $actor->company_id !== (int) $locked->supplier_company_id) {
                throw ValidationException::withMessages([
                    'credit_note' => 'Only the supplier company may create a refund for this credit note.',
                ]);
            }

            $existing = Refund::query()
                ->where('credit_note_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'refund' => $existing->fresh([
                        'creditNote',
                        'invoice',
                        'payment',
                        'buyerCompany',
                        'supplierCompany.supplierProfile',
                    ]),
                    'created' => false,
                ];
            }

            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->where('invoice_id', $locked->invoice_id)
                ->where('status', Payment::STATUS_PAID)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw ValidationException::withMessages([
                    'payment' => 'A paid payment is required before creating a refund for this credit note.',
                ]);
            }

            if ((int) $payment->buyer_company_id !== (int) $locked->buyer_company_id
                || (int) $payment->supplier_company_id !== (int) $locked->supplier_company_id
                || (int) $payment->invoice_id !== (int) $locked->invoice_id) {
                throw ValidationException::withMessages([
                    'payment' => 'Payment does not belong to the same commercial chain as the credit note.',
                ]);
            }

            $amount = (string) $locked->total;

            if (bccomp($amount, (string) $payment->amount, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Refund amount cannot exceed the paid payment amount.',
                ]);
            }

            $resolvedMethod = $method !== null && trim($method) !== ''
                ? trim($method)
                : $payment->method;

            if (! in_array($resolvedMethod, Payment::METHODS, true)) {
                throw ValidationException::withMessages([
                    'method' => 'The selected refund method is invalid.',
                ]);
            }

            $refund = new Refund;
            $refund->creditNote()->associate($locked);
            $refund->invoice_id = $locked->invoice_id;
            $refund->payment()->associate($payment);
            $refund->buyer_company_id = $locked->buyer_company_id;
            $refund->supplier_company_id = $locked->supplier_company_id;
            $refund->status = Refund::STATUS_PENDING;
            $refund->method = $resolvedMethod;
            $refund->currency = $locked->currency;
            $refund->amount = $amount;
            $refund->reason = $reason !== null && trim($reason) !== ''
                ? trim($reason)
                : $locked->reason;
            $refund->number = 'PENDING-'.$locked->id.'-'.uniqid('', true);
            $refund->save();

            $refund->number = sprintf('REF-%d-%06d', (int) $refund->created_at->year, (int) $refund->id);
            $refund->save();

            return [
                'refund' => $refund->fresh([
                    'creditNote',
                    'invoice',
                    'payment',
                    'buyerCompany',
                    'supplierCompany.supplierProfile',
                ]),
                'created' => true,
            ];
        });
    }

    public function process(Refund $refund): Refund
    {
        return $this->transition($refund, Refund::STATUS_PROCESSED);
    }

    public function fail(Refund $refund): Refund
    {
        return $this->transition($refund, Refund::STATUS_FAILED);
    }

    public function cancel(Refund $refund): Refund
    {
        return $this->transition($refund, Refund::STATUS_CANCELLED);
    }

    private function transition(Refund $refund, string $to): Refund
    {
        return DB::transaction(function () use ($refund, $to) {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            try {
                $locked->transitionTo($to);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'refund' => $exception->getMessage(),
                ]);
            }

            return $locked->fresh([
                'creditNote',
                'invoice',
                'payment',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ]);
        });
    }
}
