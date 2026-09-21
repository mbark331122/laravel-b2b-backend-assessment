<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Permission extends Model
{
    public const RFQ_READ = 'rfq.read';

    public const RFQ_CREATE = 'rfq.create';

    public const RFQ_UPDATE = 'rfq.update';

    public const RFQ_APPROVE = 'rfq.approve';

    public const RFQ_DELETE = 'rfq.delete';

    public const RFQ_SUBMIT = 'rfq.submit';

    public const RFQ_CANCEL = 'rfq.cancel';

    public const RFQ_CLOSE = 'rfq.close';

    public const RFQ_DISTRIBUTION_READ = 'rfq.distribution.read';

    public const RFQ_DISTRIBUTION_CREATE = 'rfq.distribution.create';

    public const RFQ_DISTRIBUTION_WITHDRAW = 'rfq.distribution.withdraw';

    public const SUPPLIER_RFQ_READ = 'supplier.rfq.read';

    public const QUOTATION_READ = 'quotation.read';

    public const QUOTATION_CREATE = 'quotation.create';

    public const QUOTATION_UPDATE = 'quotation.update';

    public const QUOTATION_DELETE = 'quotation.delete';

    public const QUOTATION_SUBMIT = 'quotation.submit';

    public const QUOTATION_WITHDRAW = 'quotation.withdraw';

    public const QUOTATION_COMPARE = 'quotation.compare';

    public const NEGOTIATION_READ = 'negotiation.read';

    public const NEGOTIATION_CREATE = 'negotiation.create';

    public const NEGOTIATION_OFFER_CREATE = 'negotiation.offer.create';

    public const NEGOTIATION_OFFER_ACCEPT = 'negotiation.offer.accept';

    public const NEGOTIATION_REJECT = 'negotiation.reject';

    public const NEGOTIATION_WITHDRAW = 'negotiation.withdraw';

    public const PURCHASE_ORDER_READ = 'purchase_order.read';

    public const PURCHASE_ORDER_CREATE = 'purchase_order.create';

    public const PURCHASE_ORDER_SUBMIT = 'purchase_order.submit';

    public const PURCHASE_ORDER_CANCEL = 'purchase_order.cancel';

    public const PURCHASE_ORDER_CONFIRM = 'purchase_order.confirm';

    public const PURCHASE_ORDER_REJECT = 'purchase_order.reject';

    public const PURCHASE_ORDER_COMPLETE = 'purchase_order.complete';

    public const INVOICE_READ = 'invoice.read';

    public const INVOICE_CREATE = 'invoice.create';

    public const INVOICE_ISSUE = 'invoice.issue';

    public const INVOICE_CANCEL = 'invoice.cancel';

    public const INVOICE_VOID = 'invoice.void';

    public const PAYMENT_READ = 'payment.read';

    public const PAYMENT_CREATE = 'payment.create';

    public const PAYMENT_MARK_PAID = 'payment.mark_paid';

    public const PAYMENT_MARK_FAILED = 'payment.mark_failed';

    public const PAYMENT_CANCEL = 'payment.cancel';

    public const SHIPMENT_READ = 'shipment.read';

    public const SHIPMENT_CREATE = 'shipment.create';

    public const SHIPMENT_UPDATE = 'shipment.update';

    public const SHIPMENT_PROCESS = 'shipment.process';

    public const SHIPMENT_SHIP = 'shipment.ship';

    public const SHIPMENT_DELIVER = 'shipment.deliver';

    public const SHIPMENT_CANCEL = 'shipment.cancel';

    public const DELIVERY_CONFIRMATION_READ = 'delivery_confirmation.read';

    public const DELIVERY_CONFIRMATION_CREATE = 'delivery_confirmation.create';

    public const RMA_READ = 'rma.read';

    public const RMA_CREATE = 'rma.create';

    public const RMA_CANCEL = 'rma.cancel';

    public const RMA_APPROVE = 'rma.approve';

    public const RMA_REJECT = 'rma.reject';

    public const RMA_RECEIVE = 'rma.receive';

    public const RMA_CLOSE = 'rma.close';

    public const RETURN_SHIPMENT_READ = 'return_shipment.read';

    public const RETURN_SHIPMENT_CREATE = 'return_shipment.create';

    public const RETURN_SHIPMENT_UPDATE = 'return_shipment.update';

    public const RETURN_SHIPMENT_CANCEL = 'return_shipment.cancel';

    public const RETURN_SHIPMENT_SHIP = 'return_shipment.ship';

    public const RETURN_SHIPMENT_DELIVER = 'return_shipment.deliver';

    public const CREDIT_NOTE_READ = 'credit_note.read';

    public const CREDIT_NOTE_CREATE = 'credit_note.create';

    public const CREDIT_NOTE_ISSUE = 'credit_note.issue';

    public const CREDIT_NOTE_CANCEL = 'credit_note.cancel';

    public const CREDIT_NOTE_VOID = 'credit_note.void';

    public const REFUND_READ = 'refund.read';

    public const REFUND_CREATE = 'refund.create';

    public const REFUND_PROCESS = 'refund.process';

    public const REFUND_FAIL = 'refund.fail';

    public const REFUND_CANCEL = 'refund.cancel';

    public const PURCHASE_ORDER_PARTY_READ = 'purchase_order.party.read';

    public const PURCHASE_ORDER_PARTY_MANAGE = 'purchase_order.party.manage';

    public const PURCHASE_ORDER_PARTY_IDENTITY_READ = 'purchase_order.party.identity.read';

    public const PURCHASE_ORDER_COMMISSION_READ = 'purchase_order.commission.read';

    public const PURCHASE_ORDER_CONFIDENTIAL_READ = 'purchase_order.confidential.read';

    public const BANK_READ = 'bank.read';

    public const BANK_CHANGE_REQUEST = 'bank.change_request';

    public const BANK_APPROVE = 'bank.approve';

    public const PRODUCT_READ = 'product.read';

    public const PRODUCT_CREATE = 'product.create';

    public const PRODUCT_UPDATE = 'product.update';

    public const PRODUCT_DELETE = 'product.delete';

    public const PRODUCT_REVIEW = 'product.review';

    public const SUPPLIER_PROFILE_READ = 'supplier.profile.read';

    public const SUPPLIER_PROFILE_CREATE = 'supplier.profile.create';

    public const SUPPLIER_PROFILE_UPDATE = 'supplier.profile.update';

    public const BRAND_CREATE = 'brand.create';

    /**
     * Platform permission catalog.
     *
     * Naming convention for future domains: `{domain}.{action}` (e.g. `quotation.create`).
     * Do not rename or remove the minimum RFQ/bank permissions below.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::RFQ_READ,
            self::RFQ_CREATE,
            self::RFQ_UPDATE,
            self::RFQ_APPROVE,
            self::RFQ_DELETE,
            self::RFQ_SUBMIT,
            self::RFQ_CANCEL,
            self::RFQ_CLOSE,
            self::RFQ_DISTRIBUTION_READ,
            self::RFQ_DISTRIBUTION_CREATE,
            self::RFQ_DISTRIBUTION_WITHDRAW,
            self::SUPPLIER_RFQ_READ,
            self::QUOTATION_READ,
            self::QUOTATION_CREATE,
            self::QUOTATION_UPDATE,
            self::QUOTATION_DELETE,
            self::QUOTATION_SUBMIT,
            self::QUOTATION_WITHDRAW,
            self::QUOTATION_COMPARE,
            self::NEGOTIATION_READ,
            self::NEGOTIATION_CREATE,
            self::NEGOTIATION_OFFER_CREATE,
            self::NEGOTIATION_OFFER_ACCEPT,
            self::NEGOTIATION_REJECT,
            self::NEGOTIATION_WITHDRAW,
            self::PURCHASE_ORDER_READ,
            self::PURCHASE_ORDER_CREATE,
            self::PURCHASE_ORDER_SUBMIT,
            self::PURCHASE_ORDER_CANCEL,
            self::PURCHASE_ORDER_CONFIRM,
            self::PURCHASE_ORDER_REJECT,
            self::PURCHASE_ORDER_COMPLETE,
            self::PURCHASE_ORDER_PARTY_READ,
            self::PURCHASE_ORDER_PARTY_MANAGE,
            self::PURCHASE_ORDER_PARTY_IDENTITY_READ,
            self::PURCHASE_ORDER_COMMISSION_READ,
            self::PURCHASE_ORDER_CONFIDENTIAL_READ,
            self::INVOICE_READ,
            self::INVOICE_CREATE,
            self::INVOICE_ISSUE,
            self::INVOICE_CANCEL,
            self::INVOICE_VOID,
            self::PAYMENT_READ,
            self::PAYMENT_CREATE,
            self::PAYMENT_MARK_PAID,
            self::PAYMENT_MARK_FAILED,
            self::PAYMENT_CANCEL,
            self::SHIPMENT_READ,
            self::SHIPMENT_CREATE,
            self::SHIPMENT_UPDATE,
            self::SHIPMENT_PROCESS,
            self::SHIPMENT_SHIP,
            self::SHIPMENT_DELIVER,
            self::SHIPMENT_CANCEL,
            self::DELIVERY_CONFIRMATION_READ,
            self::DELIVERY_CONFIRMATION_CREATE,
            self::RMA_READ,
            self::RMA_CREATE,
            self::RMA_CANCEL,
            self::RMA_APPROVE,
            self::RMA_REJECT,
            self::RMA_RECEIVE,
            self::RMA_CLOSE,
            self::RETURN_SHIPMENT_READ,
            self::RETURN_SHIPMENT_CREATE,
            self::RETURN_SHIPMENT_UPDATE,
            self::RETURN_SHIPMENT_CANCEL,
            self::RETURN_SHIPMENT_SHIP,
            self::RETURN_SHIPMENT_DELIVER,
            self::CREDIT_NOTE_READ,
            self::CREDIT_NOTE_CREATE,
            self::CREDIT_NOTE_ISSUE,
            self::CREDIT_NOTE_CANCEL,
            self::CREDIT_NOTE_VOID,
            self::REFUND_READ,
            self::REFUND_CREATE,
            self::REFUND_PROCESS,
            self::REFUND_FAIL,
            self::REFUND_CANCEL,
            self::BANK_READ,
            self::BANK_CHANGE_REQUEST,
            self::BANK_APPROVE,
            self::PRODUCT_READ,
            self::PRODUCT_CREATE,
            self::PRODUCT_UPDATE,
            self::PRODUCT_DELETE,
            self::PRODUCT_REVIEW,
            self::SUPPLIER_PROFILE_READ,
            self::SUPPLIER_PROFILE_CREATE,
            self::SUPPLIER_PROFILE_UPDATE,
            self::BRAND_CREATE,
        ];
    }

    /**
     * Permissions granted to buyer company users. Approval stays with admin.
     *
     * @return list<string>
     */
    public static function companyUserNames(): array
    {
        return [
            self::RFQ_READ,
            self::RFQ_CREATE,
            self::RFQ_UPDATE,
            self::RFQ_DELETE,
            self::RFQ_SUBMIT,
            self::RFQ_CANCEL,
            self::RFQ_CLOSE,
            self::RFQ_DISTRIBUTION_READ,
            self::RFQ_DISTRIBUTION_CREATE,
            self::RFQ_DISTRIBUTION_WITHDRAW,
            self::QUOTATION_READ,
            self::QUOTATION_COMPARE,
            self::NEGOTIATION_READ,
            self::NEGOTIATION_CREATE,
            self::NEGOTIATION_OFFER_CREATE,
            self::NEGOTIATION_OFFER_ACCEPT,
            self::NEGOTIATION_REJECT,
            self::NEGOTIATION_WITHDRAW,
            self::PURCHASE_ORDER_READ,
            self::PURCHASE_ORDER_CREATE,
            self::PURCHASE_ORDER_SUBMIT,
            self::PURCHASE_ORDER_CANCEL,
            self::PURCHASE_ORDER_COMPLETE,
            self::PURCHASE_ORDER_PARTY_READ,
            self::PURCHASE_ORDER_PARTY_MANAGE,
            self::PURCHASE_ORDER_PARTY_IDENTITY_READ,
            self::INVOICE_READ,
            self::PAYMENT_READ,
            self::PAYMENT_CREATE,
            self::PAYMENT_CANCEL,
            self::SHIPMENT_READ,
            self::DELIVERY_CONFIRMATION_READ,
            self::DELIVERY_CONFIRMATION_CREATE,
            self::RMA_READ,
            self::RMA_CREATE,
            self::RMA_CANCEL,
            self::RETURN_SHIPMENT_READ,
            self::RETURN_SHIPMENT_CREATE,
            self::RETURN_SHIPMENT_UPDATE,
            self::RETURN_SHIPMENT_CANCEL,
            self::CREDIT_NOTE_READ,
            self::REFUND_READ,
            self::BANK_READ,
            self::BANK_CHANGE_REQUEST,
            self::PRODUCT_READ,
            self::SUPPLIER_PROFILE_READ,
        ];
    }

    /**
     * Permissions granted to supplier company users.
     * Suppliers intentionally do not receive RFQ create/update/lifecycle permissions.
     * Sprint 5 grants read-only access to RFQs explicitly distributed to their company.
     *
     * @return list<string>
     */
    public static function supplierUserNames(): array
    {
        return [
            self::PRODUCT_READ,
            self::PRODUCT_CREATE,
            self::PRODUCT_UPDATE,
            self::PRODUCT_DELETE,
            self::SUPPLIER_PROFILE_READ,
            self::SUPPLIER_PROFILE_CREATE,
            self::SUPPLIER_PROFILE_UPDATE,
            self::SUPPLIER_RFQ_READ,
            self::QUOTATION_READ,
            self::QUOTATION_CREATE,
            self::QUOTATION_UPDATE,
            self::QUOTATION_DELETE,
            self::QUOTATION_SUBMIT,
            self::QUOTATION_WITHDRAW,
            self::NEGOTIATION_READ,
            self::NEGOTIATION_CREATE,
            self::NEGOTIATION_OFFER_CREATE,
            self::NEGOTIATION_OFFER_ACCEPT,
            self::NEGOTIATION_REJECT,
            self::NEGOTIATION_WITHDRAW,
            self::PURCHASE_ORDER_READ,
            self::PURCHASE_ORDER_CONFIRM,
            self::PURCHASE_ORDER_REJECT,
            self::PURCHASE_ORDER_PARTY_READ,
            self::PURCHASE_ORDER_PARTY_IDENTITY_READ,
            self::INVOICE_READ,
            self::INVOICE_CREATE,
            self::INVOICE_ISSUE,
            self::INVOICE_CANCEL,
            self::INVOICE_VOID,
            self::PAYMENT_READ,
            self::PAYMENT_MARK_PAID,
            self::PAYMENT_MARK_FAILED,
            self::SHIPMENT_READ,
            self::SHIPMENT_CREATE,
            self::SHIPMENT_UPDATE,
            self::SHIPMENT_PROCESS,
            self::SHIPMENT_SHIP,
            self::SHIPMENT_DELIVER,
            self::SHIPMENT_CANCEL,
            self::DELIVERY_CONFIRMATION_READ,
            self::RMA_READ,
            self::RMA_APPROVE,
            self::RMA_REJECT,
            self::RMA_RECEIVE,
            self::RMA_CLOSE,
            self::RETURN_SHIPMENT_READ,
            self::RETURN_SHIPMENT_SHIP,
            self::RETURN_SHIPMENT_DELIVER,
            self::CREDIT_NOTE_READ,
            self::CREDIT_NOTE_CREATE,
            self::CREDIT_NOTE_ISSUE,
            self::CREDIT_NOTE_CANCEL,
            self::CREDIT_NOTE_VOID,
            self::REFUND_READ,
            self::REFUND_CREATE,
            self::REFUND_PROCESS,
            self::REFUND_FAIL,
            self::REFUND_CANCEL,
        ];
    }

    /**
     * Permissions for intermediary company users (multi-party confidentiality).
     *
     * @return list<string>
     */
    public static function intermediaryUserNames(): array
    {
        return [
            self::PURCHASE_ORDER_READ,
            self::PURCHASE_ORDER_PARTY_READ,
            self::PURCHASE_ORDER_PARTY_IDENTITY_READ,
            self::PURCHASE_ORDER_COMMISSION_READ,
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
