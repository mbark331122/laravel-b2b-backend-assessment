<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccountHistory extends Model
{
    /**
     * @return BelongsTo<SupplierBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierBankAccount::class, 'supplier_bank_account_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'supplier_bank_account_id' => $this->supplier_bank_account_id,
            'beneficiary_name' => $this->beneficiary_name,
            'bank_name' => $this->bank_name,
            'iban' => $this->iban,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
