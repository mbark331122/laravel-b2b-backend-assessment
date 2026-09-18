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
            self::BANK_READ,
            self::BANK_CHANGE_REQUEST,
            self::PRODUCT_READ,
            self::SUPPLIER_PROFILE_READ,
        ];
    }

    /**
     * Permissions granted to supplier company users.
     *
     * @return list<string>
     */
    public static function supplierUserNames(): array
    {
        return [
            self::RFQ_READ,
            self::PRODUCT_READ,
            self::PRODUCT_CREATE,
            self::PRODUCT_UPDATE,
            self::PRODUCT_DELETE,
            self::SUPPLIER_PROFILE_READ,
            self::SUPPLIER_PROFILE_CREATE,
            self::SUPPLIER_PROFILE_UPDATE,
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
