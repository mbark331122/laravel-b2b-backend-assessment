<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name'])]
class Supplier extends Model
{
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasOne<SupplierBankAccount, $this>
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(SupplierBankAccount::class);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id);
    }
}
