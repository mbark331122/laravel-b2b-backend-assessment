<?php

namespace App\Models;

use App\Services\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Fillable([
    'field',
    'current_value',
    'proposed_value',
    'source',
    'confidence',
    'status',
])]
class RfqProposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Official RFQ fields that approval may write. Never company_id or status.
     *
     * @var list<string>
     */
    public const APPLIABLE_FIELDS = [
        'commodity',
        'specification',
        'quantity',
        'unit',
        'incoterm',
        'destination',
    ];

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<AiExtraction, $this>
     */
    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    public function approve(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only pending proposals can be approved.');
        }

        if (! in_array($this->field, self::APPLIABLE_FIELDS, true)) {
            throw new InvalidArgumentException('Proposal field cannot be applied to the official RFQ.');
        }

        DB::transaction(function () use ($reason): void {
            $rfq = $this->rfq()->lockForUpdate()->firstOrFail();
            $beforeValue = $rfq->{$this->field};
            $afterValue = $this->castProposedValue();

            $rfq->{$this->field} = $afterValue;
            $rfq->save();

            $this->status = self::STATUS_APPROVED;
            $this->save();

            $this->aiExtraction->status = AiExtraction::STATUS_APPROVED;
            $this->aiExtraction->save();

            $logger = app(AuditLogger::class);
            $change = [$this->field => $beforeValue];
            $applied = [$this->field => $afterValue];

            $logger->record(
                AuditLog::PROPOSAL_APPROVED,
                $this,
                $rfq->company_id,
                before: $change,
                after: $applied,
                reason: $reason,
            );
            $logger->record(
                AuditLog::RFQ_APPROVED,
                $rfq,
                $rfq->company_id,
                before: $change,
                after: $applied,
                reason: $reason,
            );
        });
    }

    public function reject(?string $reason = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only pending proposals can be rejected.');
        }

        DB::transaction(function () use ($reason): void {
            $rfq = $this->rfq()->firstOrFail();

            $this->status = self::STATUS_REJECTED;
            $this->save();

            $this->aiExtraction->status = AiExtraction::STATUS_REJECTED;
            $this->aiExtraction->save();

            app(AuditLogger::class)->record(
                AuditLog::PROPOSAL_REJECTED,
                $this,
                $rfq->company_id,
                before: [$this->field => $this->current_value],
                after: [$this->field => $rfq->{$this->field}],
                reason: $reason,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'rfq_id' => $this->rfq_id,
            'ai_extraction_id' => $this->ai_extraction_id,
            'field' => $this->field,
            'current_value' => $this->current_value,
            'proposed_value' => $this->proposed_value,
            'source' => $this->source,
            'confidence' => (float) $this->confidence,
            'status' => $this->status,
        ];
    }

    private function castProposedValue(): mixed
    {
        if ($this->field === 'quantity') {
            return (int) $this->proposed_value;
        }

        return $this->proposed_value;
    }
}
