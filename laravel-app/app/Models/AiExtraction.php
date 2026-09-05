<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'commodity',
    'specification',
    'quantity',
    'unit',
    'incoterm',
    'destination',
    'confidence',
    'source',
    'status',
])]
class AiExtraction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return HasMany<RfqProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(RfqProposal::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'commodity' => $this->commodity,
            'specification' => $this->specification,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'incoterm' => $this->incoterm,
            'destination' => $this->destination,
            'confidence' => (float) $this->confidence,
            'source' => $this->source,
            'status' => $this->status,
            'proposals' => $this->proposals
                ->map(fn (RfqProposal $proposal) => $proposal->toApiArray())
                ->values()
                ->all(),
        ];
    }
}
