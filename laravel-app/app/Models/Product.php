<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'sku',
    'description',
    'unit',
    'minimum_order_quantity',
    'wholesale_price',
    'currency',
    'status',
    'product_category_id',
])]
class Product extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    public const CURRENCIES = ['USD', 'EUR', 'SAR', 'AED', 'GBP'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_order_quantity' => 'integer',
            'wholesale_price' => 'decimal:2',
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
     * @return BelongsTo<SupplierProfile, $this>
     */
    public function supplierProfile(): BelongsTo
    {
        return $this->belongsTo(SupplierProfile::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Suppliers see their own products. Buyers see active catalog products only. Admin sees all.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isSupplierUser()) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['category', 'supplierProfile']);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'supplier_profile_id' => $this->supplier_profile_id,
            'product_category_id' => $this->product_category_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'unit' => $this->unit,
            'minimum_order_quantity' => $this->minimum_order_quantity,
            'wholesale_price' => (string) $this->wholesale_price,
            'currency' => $this->currency,
            'status' => $this->status,
            'category' => $this->category?->toApiArray(),
            'supplier_profile' => $this->supplierProfile?->toApiArray(),
        ];
    }
}
