<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const TYPE_RFQ_SUBMITTED = 'rfq.submitted';

    public const TYPE_RFQ_DISTRIBUTED = 'rfq.distributed';

    public const TYPE_PURCHASE_ORDER_SUBMITTED = 'purchase_order.submitted';

    public const TYPE_PURCHASE_ORDER_CONFIRMED = 'purchase_order.confirmed';

    public const TYPE_PURCHASE_ORDER_REJECTED = 'purchase_order.rejected';

    public const TYPE_PURCHASE_ORDER_COMPLETED = 'purchase_order.completed';

    public const TYPE_PAYMENT_CREATED = 'payment.created';

    public const TYPE_PAYMENT_MARKED_PAID = 'payment.marked_paid';

    public const TYPE_PAYMENT_MARKED_FAILED = 'payment.marked_failed';

    public const TYPE_PAYMENT_CANCELLED = 'payment.cancelled';

    public const TYPE_SHIPMENT_CREATED = 'shipment.created';

    public const TYPE_SHIPMENT_SHIPPED = 'shipment.shipped';

    public const TYPE_SHIPMENT_DELIVERED = 'shipment.delivered';

    public const TYPE_SHIPMENT_CANCELLED = 'shipment.cancelled';

    public const TYPE_DELIVERY_CONFIRMATION_CREATED = 'delivery_confirmation.created';

    public const TYPE_RMA_CREATED = 'rma.created';

    public const TYPE_RMA_APPROVED = 'rma.approved';

    public const TYPE_RMA_REJECTED = 'rma.rejected';

    public const TYPE_RMA_RECEIVED = 'rma.received';

    public const TYPE_RMA_CLOSED = 'rma.closed';

    public const TYPE_RETURN_SHIPMENT_CREATED = 'return_shipment.created';

    public const TYPE_RETURN_SHIPMENT_SHIPPED = 'return_shipment.shipped';

    public const TYPE_RETURN_SHIPMENT_DELIVERED = 'return_shipment.delivered';

    public const TYPE_RETURN_SHIPMENT_CANCELLED = 'return_shipment.cancelled';

    public const TYPE_CREDIT_NOTE_ISSUED = 'credit_note.issued';

    public const TYPE_CREDIT_NOTE_CANCELLED = 'credit_note.cancelled';

    public const TYPE_CREDIT_NOTE_VOIDED = 'credit_note.voided';

    public const TYPE_REFUND_PROCESSED = 'refund.processed';

    public const TYPE_REFUND_FAILED = 'refund.failed';

    public const TYPE_REFUND_CANCELLED = 'refund.cancelled';

    public const TYPE_APPROVAL_REQUEST_CREATED = 'approval.request.created';

    public const TYPE_APPROVAL_REQUEST_APPROVED = 'approval.request.approved';

    public const TYPE_APPROVAL_REQUEST_REJECTED = 'approval.request.rejected';

    public const TYPE_APPROVAL_REQUEST_CANCELLED = 'approval.request.cancelled';

    public const TYPE_APPROVAL_STEP_REQUIRED = 'approval.step.required';

    /**
     * Ownership fields are never mass-assignable from client input.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'read_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Safe API payload — never includes Assessment 2 identities or secrets.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'metadata' => $this->metadata ?? [],
            'is_read' => $this->isRead(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
