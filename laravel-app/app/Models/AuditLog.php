<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const RFQ_CREATED = 'rfq.created';

    public const RFQ_UPDATED = 'rfq.updated';

    public const RFQ_APPROVED = 'rfq.approved';

    public const PROPOSAL_CREATED = 'proposal.created';

    public const PROPOSAL_APPROVED = 'proposal.approved';

    public const PROPOSAL_REJECTED = 'proposal.rejected';

    public const BANK_CHANGE_REQUESTED = 'bank.change_requested';

    public const BANK_CHANGE_APPROVED = 'bank.change_approved';

    public const BANK_CHANGE_REJECTED = 'bank.change_rejected';

    public const BANK_ACCOUNT_CHANGED = 'bank.account.changed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'company_id',
        'action',
        'auditable_type',
        'auditable_id',
        'before_value',
        'after_value',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_value' => 'array',
            'after_value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
