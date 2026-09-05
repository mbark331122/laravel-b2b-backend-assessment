<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['commodity', 'specification', 'quantity', 'unit', 'incoterm', 'destination', 'status'])]
class Rfq extends Model
{
    public const STATUS_DRAFT = 'draft';

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<AiExtraction, $this>
     */
    public function aiExtractions(): HasMany
    {
        return $this->hasMany(AiExtraction::class);
    }

    /**
     * @return HasMany<RfqProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(RfqProposal::class);
    }

    /**
     * Company users only see their own company's RFQs. Admin is not tenant-scoped.
     *
     * @param  Builder<Rfq>  $query
     * @return Builder<Rfq>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'commodity' => $this->commodity,
            'specification' => $this->specification,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'incoterm' => $this->incoterm,
            'destination' => $this->destination,
            'status' => $this->status,
        ];
    }
}
