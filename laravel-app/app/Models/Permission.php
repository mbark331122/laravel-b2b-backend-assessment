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
