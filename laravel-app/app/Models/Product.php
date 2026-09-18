<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'name',
    'sku',
    'description',
    'unit',
    'minimum_order_quantity',
    'maximum_order_quantity',
    'quantity_increment',
    'wholesale_price',
    'currency',
    'product_category_id',
    'brand_id',
])]
class Product extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Buyer-facing catalog visibility.
     *
     * @var list<string>
     */
    public const BUYER_VISIBLE_STATUSES = [
        self::STATUS_PUBLISHED,
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PENDING_REVIEW, self::STATUS_ARCHIVED],
        self::STATUS_PENDING_REVIEW => [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [self::STATUS_PUBLISHED, self::STATUS_PENDING_REVIEW, self::STATUS_ARCHIVED],
        self::STATUS_PUBLISHED => [self::STATUS_ARCHIVED, self::STATUS_PENDING_REVIEW],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW],
        self::STATUS_ARCHIVED => [self::STATUS_DRAFT],
    ];

    /**
     * Transitions available to supplier actors (not platform reviewers).
     *
     * @var array<string, list<string>>
     */
    public const SUPPLIER_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PENDING_REVIEW, self::STATUS_ARCHIVED],
        self::STATUS_PENDING_REVIEW => [self::STATUS_DRAFT],
        self::STATUS_PUBLISHED => [self::STATUS_PENDING_REVIEW],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW],
        self::STATUS_ARCHIVED => [self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_PENDING_REVIEW],
    ];

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
            'maximum_order_quantity' => 'integer',
            'quantity_increment' => 'integer',
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
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductSpecification, $this>
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductPriceTier, $this>
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity')->orderBy('id');
    }

    public function isBuyerVisible(): bool
    {
        return in_array($this->status, self::BUYER_VISIBLE_STATUSES, true);
    }

    /**
     * @return list<string>
     */
    public function allowedTransitionsFor(User $user): array
    {
        $from = $this->status;
        $all = self::TRANSITIONS[$from] ?? [];

        if ($user->isAdmin()) {
            return $all;
        }

        return array_values(array_intersect($all, self::SUPPLIER_TRANSITIONS[$from] ?? []));
    }

    public function transitionTo(string $to, User $user): void
    {
        if (! in_array($to, $this->allowedTransitionsFor($user), true)) {
            throw new InvalidArgumentException("Invalid product lifecycle transition from {$this->status} to {$to}.");
        }

        $this->status = $to;
        $this->save();
    }

    /**
     * Suppliers see their own products. Buyers see published catalog products only. Admin sees all.
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

        return $query->whereIn('status', self::BUYER_VISIBLE_STATUSES);
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function scopeApplyCatalogFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term);
            });
        }

        if (! empty($filters['product_category_id'])) {
            $query->where('product_category_id', (int) $filters['product_category_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (! empty($filters['supplier_profile_id'])) {
            $query->where('supplier_profile_id', (int) $filters['supplier_profile_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', (string) $filters['currency']);
        }

        if (isset($filters['min_moq']) && $filters['min_moq'] !== '' && $filters['min_moq'] !== null) {
            $query->where('minimum_order_quantity', '>=', (int) $filters['min_moq']);
        }

        if (isset($filters['max_moq']) && $filters['max_moq'] !== '' && $filters['max_moq'] !== null) {
            $query->where('minimum_order_quantity', '<=', (int) $filters['max_moq']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers']);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'supplier_profile_id' => $this->supplier_profile_id,
            'product_category_id' => $this->product_category_id,
            'brand_id' => $this->brand_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'unit' => $this->unit,
            'minimum_order_quantity' => $this->minimum_order_quantity,
            'maximum_order_quantity' => $this->maximum_order_quantity,
            'quantity_increment' => $this->quantity_increment,
            'wholesale_price' => (string) $this->wholesale_price,
            'currency' => $this->currency,
            'status' => $this->status,
            'category' => $this->category?->toApiArray(),
            'brand' => $this->brand?->toApiArray(),
            'supplier_profile' => $this->supplierProfile?->toApiArray(),
            'specifications' => $this->specifications->map(fn (ProductSpecification $spec) => $spec->toApiArray())->values()->all(),
            'price_tiers' => $this->priceTiers->map(fn (ProductPriceTier $tier) => $tier->toApiArray())->values()->all(),
        ];
    }
}
