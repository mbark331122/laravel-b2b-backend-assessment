<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'approval_type', 'is_active', 'min_amount', 'currency', 'priority'])]
class ApprovalPolicy extends Model
{
    public const TYPE_RFQ_SUBMIT = 'rfq.submit';

    public const TYPE_PURCHASE_ORDER_SUBMIT = 'purchase_order.submit';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_RFQ_SUBMIT,
            self::TYPE_PURCHASE_ORDER_SUBMIT,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_amount' => 'decimal:2',
            'priority' => 'integer',
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
     * @return HasMany<ApprovalPolicyStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalPolicyStep::class)->orderBy('step_order');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing('steps');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'approval_type' => $this->approval_type,
            'is_active' => (bool) $this->is_active,
            'min_amount' => $this->min_amount !== null ? (string) $this->min_amount : null,
            'currency' => $this->currency,
            'priority' => (int) $this->priority,
            'steps' => $this->steps->map(fn (ApprovalPolicyStep $step) => $step->toApiArray())->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
