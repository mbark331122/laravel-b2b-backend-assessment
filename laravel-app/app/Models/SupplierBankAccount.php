<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBankAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    /**
     * IBAN is not mass-assignable. It changes only through approved requests.
     *
     * @var list<string>
     */
    protected $fillable = [
        'beneficiary_name',
        'bank_name',
        'status',
    ];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<BankChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(BankChangeRequest::class);
    }

    /**
     * @return HasMany<BankAccountHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(BankAccountHistory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'beneficiary_name' => $this->beneficiary_name,
            'bank_name' => $this->bank_name,
            'iban' => $this->iban,
            'status' => $this->status,
            'history' => $this->history
                ->sortByDesc('id')
                ->values()
                ->map(fn (BankAccountHistory $history) => $history->toApiArray())
                ->all(),
            'change_requests' => $this->changeRequests
                ->sortByDesc('id')
                ->values()
                ->map(fn (BankChangeRequest $request) => $request->toApiArray())
                ->all(),
        ];
    }
}
