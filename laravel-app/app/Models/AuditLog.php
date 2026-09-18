<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const RFQ_CREATED = 'rfq.created';

    public const RFQ_UPDATED = 'rfq.updated';

    public const RFQ_DELETED = 'rfq.deleted';

    public const RFQ_SUBMITTED = 'rfq.submitted';

    public const RFQ_CANCELLED = 'rfq.cancelled';

    public const RFQ_CLOSED = 'rfq.closed';

    public const RFQ_ITEM_CREATED = 'rfq.item.created';

    public const RFQ_ITEM_UPDATED = 'rfq.item.updated';

    public const RFQ_ITEM_DELETED = 'rfq.item.deleted';

    public const RFQ_DISTRIBUTED = 'rfq.distributed';

    public const RFQ_DISTRIBUTION_WITHDRAWN = 'rfq.distribution.withdrawn';

    public const QUOTATION_CREATED = 'quotation.created';

    public const QUOTATION_UPDATED = 'quotation.updated';

    public const QUOTATION_DELETED = 'quotation.deleted';

    public const QUOTATION_SUBMITTED = 'quotation.submitted';

    public const QUOTATION_WITHDRAWN = 'quotation.withdrawn';

    public const QUOTATION_EXPIRED = 'quotation.expired';

    public const QUOTATION_ITEM_CREATED = 'quotation.item.created';

    public const QUOTATION_ITEM_UPDATED = 'quotation.item.updated';

    public const QUOTATION_ITEM_DELETED = 'quotation.item.deleted';

    public const NEGOTIATION_CREATED = 'negotiation.created';

    public const NEGOTIATION_OFFER_CREATED = 'negotiation.offer.created';

    public const NEGOTIATION_OFFER_ACCEPTED = 'negotiation.offer.accepted';

    public const NEGOTIATION_REJECTED = 'negotiation.rejected';

    public const NEGOTIATION_WITHDRAWN = 'negotiation.withdrawn';

    public const NEGOTIATION_EXPIRED = 'negotiation.expired';

    public const PURCHASE_ORDER_CREATED = 'purchase_order.created';

    public const PURCHASE_ORDER_SUBMITTED = 'purchase_order.submitted';

    public const PURCHASE_ORDER_CONFIRMED = 'purchase_order.confirmed';

    public const PURCHASE_ORDER_REJECTED = 'purchase_order.rejected';

    public const PURCHASE_ORDER_CANCELLED = 'purchase_order.cancelled';

    public const PURCHASE_ORDER_COMPLETED = 'purchase_order.completed';

    public const INVOICE_CREATED = 'invoice.created';

    public const INVOICE_ISSUED = 'invoice.issued';

    public const INVOICE_CANCELLED = 'invoice.cancelled';

    public const INVOICE_VOIDED = 'invoice.voided';

    public const PAYMENT_CREATED = 'payment.created';

    public const PAYMENT_MARKED_PAID = 'payment.marked_paid';

    public const PAYMENT_MARKED_FAILED = 'payment.marked_failed';

    public const PAYMENT_CANCELLED = 'payment.cancelled';

    public const SHIPMENT_CREATED = 'shipment.created';

    public const SHIPMENT_UPDATED = 'shipment.updated';

    public const SHIPMENT_PROCESSING = 'shipment.processing';

    public const SHIPMENT_SHIPPED = 'shipment.shipped';

    public const SHIPMENT_DELIVERED = 'shipment.delivered';

    public const SHIPMENT_CANCELLED = 'shipment.cancelled';

    public const RFQ_APPROVED = 'rfq.approved';

    public const PROPOSAL_CREATED = 'proposal.created';

    public const PROPOSAL_APPROVED = 'proposal.approved';

    public const PROPOSAL_REJECTED = 'proposal.rejected';

    public const BANK_CHANGE_REQUESTED = 'bank.change_requested';

    public const BANK_CHANGE_APPROVED = 'bank.change_approved';

    public const BANK_CHANGE_REJECTED = 'bank.change_rejected';

    public const BANK_ACCOUNT_CHANGED = 'bank.account.changed';

    public const SUPPLIER_PROFILE_CREATED = 'supplier.profile.created';

    public const SUPPLIER_PROFILE_UPDATED = 'supplier.profile.updated';

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_DELETED = 'product.deleted';

    public const PRODUCT_TRANSITIONED = 'product.transitioned';

    public const BRAND_CREATED = 'brand.created';

    public const PRODUCT_SPECIFICATIONS_UPDATED = 'product.specifications.updated';

    public const PRODUCT_PRICE_TIERS_UPDATED = 'product.price_tiers.updated';

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
