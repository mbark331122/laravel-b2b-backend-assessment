<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BankChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @return BelongsTo<SupplierBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierBankAccount::class, 'supplier_bank_account_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approve(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only pending bank change requests can be approved.');
        }

        DB::transaction(function () use ($reason): void {
            $account = $this->bankAccount()->lockForUpdate()->firstOrFail();
            $beforeIban = $account->iban;
            $afterIban = $this->proposed_iban;

            $history = new BankAccountHistory;
            $history->bankAccount()->associate($account);
            $history->beneficiary_name = $account->beneficiary_name;
            $history->bank_name = $account->bank_name;
            $history->iban = $account->iban;
            $history->status = $account->status;
            $history->save();

            $account->iban = $afterIban;
            $account->save();

            $this->status = self::STATUS_APPROVED;
            $this->save();

            $logger = app(AuditLogger::class);
            $logger->record(
                AuditLog::BANK_CHANGE_APPROVED,
                $this,
                $this->company_id,
                before: ['iban' => $beforeIban],
                after: ['iban' => $afterIban],
                reason: $reason,
            );
            $logger->record(
                AuditLog::BANK_ACCOUNT_CHANGED,
                $account,
                $this->company_id,
                before: ['iban' => $beforeIban],
                after: ['iban' => $afterIban],
                reason: $reason,
            );
        });
    }

    public function reject(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only pending bank change requests can be rejected.');
        }

        $this->status = self::STATUS_REJECTED;
        $this->save();

        app(AuditLogger::class)->record(
            AuditLog::BANK_CHANGE_REJECTED,
            $this,
            $this->company_id,
            before: ['iban' => $this->current_iban],
            after: ['iban' => $this->bankAccount->iban],
            reason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'supplier_bank_account_id' => $this->supplier_bank_account_id,
            'company_id' => $this->company_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'current_iban' => $this->current_iban,
            'proposed_iban' => $this->proposed_iban,
            'status' => $this->status,
        ];
    }
}
