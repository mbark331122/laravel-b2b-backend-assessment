<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy_steps_snapshot' => 'array',
            'target_context_snapshot' => 'array',
            'completed_at' => 'datetime',
            'current_step_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ApprovalPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'approval_policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return HasMany<ApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('step_order');
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public static function pendingLockKey(string $approvalType, string $targetType, int $targetId): string
    {
        return $approvalType.':'.$targetType.':'.$targetId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['decisions', 'requester']);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'approval_policy_id' => $this->approval_policy_id,
            'approval_type' => $this->approval_type,
            'target_type' => class_basename($this->target_type),
            'target_id' => $this->target_id,
            'status' => $this->status,
            'requester_id' => $this->requester_id,
            'current_step_order' => (int) $this->current_step_order,
            'policy_name' => $this->policy_name_snapshot,
            'policy_steps' => $this->policy_steps_snapshot,
            'target_context' => $this->target_context_snapshot,
            'rejection_reason' => $this->rejection_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'decisions' => $this->decisions->map(fn (ApprovalDecision $d) => $d->toApiArray())->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
