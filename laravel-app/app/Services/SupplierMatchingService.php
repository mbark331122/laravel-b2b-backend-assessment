<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplierMatchingService
{
    /**
     * Deterministic eligibility for RFQ distribution / discovery.
     *
     * Eligible supplier = active SupplierProfile + company.is_supplier=true.
     * Optional excludeCompanyId blocks the buyer (or any) company from matching itself.
     *
     * @return Builder<SupplierProfile>
     */
    public function eligibleProfilesQuery(?int $excludeCompanyId = null): Builder
    {
        $query = SupplierProfile::query()
            ->where('status', SupplierProfile::STATUS_ACTIVE)
            ->whereHas('company', function ($company) use ($excludeCompanyId): void {
                $company->where('is_supplier', true);
                if ($excludeCompanyId !== null) {
                    $company->where('id', '!=', $excludeCompanyId);
                }
            });

        return $query;
    }

    /**
     * Apply buyer discovery filters on eligible supplier profiles.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<SupplierProfile>
     */
    public function discoveryQuery(array $filters = [], ?int $excludeCompanyId = null): Builder
    {
        $query = $this->eligibleProfilesQuery($excludeCompanyId);

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('display_name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $term));
            });
        }

        if (! empty($filters['product_category_id']) || ! empty($filters['brand_id']) || ! empty($filters['product_q'])) {
            $query->whereHas('products', function ($products) use ($filters): void {
                $products->where('status', Product::STATUS_PUBLISHED);

                if (! empty($filters['product_category_id'])) {
                    $products->where('product_category_id', (int) $filters['product_category_id']);
                }

                if (! empty($filters['brand_id'])) {
                    $products->where('brand_id', (int) $filters['brand_id']);
                }

                if (! empty($filters['product_q'])) {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['product_q']).'%';
                    $products->where(function ($inner) use ($term): void {
                        $inner->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                    });
                }
            });
        }

        return $query->orderBy('display_name')->orderBy('id');
    }

    /**
     * Match suppliers for an RFQ using structured catalog data (deterministic, no ranking scores).
     *
     * @return Collection<int, SupplierProfile>
     */
    public function matchForRfq(Rfq $rfq): Collection
    {
        $rfq->loadMissing(['items.product']);

        $productIds = $rfq->items
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $categoryIds = $rfq->items
            ->map(function ($item) {
                if ($item->product?->product_category_id) {
                    return (int) $item->product->product_category_id;
                }

                $snapshotCategory = $item->product_snapshot['category']['id'] ?? null;

                return $snapshotCategory !== null ? (int) $snapshotCategory : null;
            })
            ->filter()
            ->unique()
            ->values();

        // Without structured catalog criteria there is nothing deterministic to match on.
        if ($productIds->isEmpty() && $categoryIds->isEmpty()) {
            return collect();
        }

        $query = $this->eligibleProfilesQuery($rfq->company_id)
            ->whereHas('products', function ($products) use ($productIds, $categoryIds): void {
                $products->where('status', Product::STATUS_PUBLISHED)
                    ->where(function ($inner) use ($productIds, $categoryIds): void {
                        if ($productIds->isNotEmpty()) {
                            $inner->whereIn('id', $productIds->all());
                        }
                        if ($categoryIds->isNotEmpty()) {
                            $method = $productIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                            $inner->{$method}('product_category_id', $categoryIds->all());
                        }
                    });
            });

        return $query->orderBy('display_name')->orderBy('id')->get();
    }

    public function assertEligibleSupplierProfile(SupplierProfile $profile, Rfq $rfq): void
    {
        $company = $profile->company;

        if ($company === null || ! $company->isSupplier()) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'Supplier is not eligible for RFQ distribution.',
            ]);
        }

        if ($profile->status !== SupplierProfile::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'Inactive supplier profiles cannot receive RFQs.',
            ]);
        }

        if ((int) $company->id === (int) $rfq->company_id) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'Cannot distribute an RFQ to the buyer company.',
            ]);
        }

        $matches = $this->matchForRfq($rfq)->pluck('id');
        if (! $matches->contains($profile->id)) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'Supplier does not match this RFQ catalog criteria.',
            ]);
        }
    }

    /**
     * @param  list<int>  $supplierProfileIds
     * @return Collection<int, RfqDistribution>
     */
    public function distribute(Rfq $rfq, array $supplierProfileIds): Collection
    {
        if ($rfq->status !== Rfq::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'rfq' => 'Only submitted RFQs can be distributed.',
            ]);
        }

        $ids = collect($supplierProfileIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'At least one supplier profile is required.',
            ]);
        }

        $profiles = SupplierProfile::query()->with('company')->whereIn('id', $ids->all())->get();
        if ($profiles->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'supplier_profile_ids' => 'One or more supplier profiles are invalid.',
            ]);
        }

        $distributions = collect();

        foreach ($profiles as $profile) {
            $this->assertEligibleSupplierProfile($profile, $rfq);

            $existing = RfqDistribution::query()
                ->where('rfq_id', $rfq->id)
                ->where('supplier_company_id', $profile->company_id)
                ->first();

            if ($existing && $existing->isActive()) {
                throw ValidationException::withMessages([
                    'supplier_profile_ids' => 'RFQ is already distributed to supplier profile '.$profile->id.'.',
                ]);
            }

            if ($existing) {
                // Reactivate a previously withdrawn distribution (one row per RFQ+supplier).
                $existing->supplier_profile_id = $profile->id;
                $existing->markSent();
                $distributions->push($existing->fresh(['supplierProfile', 'supplierCompany']));

                continue;
            }

            $distribution = new RfqDistribution;
            $distribution->rfq()->associate($rfq);
            $distribution->supplierCompany()->associate($profile->company);
            $distribution->supplierProfile()->associate($profile);
            $distribution->status = RfqDistribution::STATUS_SENT;
            $distribution->distributed_at = now();
            $distribution->save();

            $distributions->push($distribution->fresh(['supplierProfile', 'supplierCompany']));
        }

        return $distributions;
    }

    public function withdraw(RfqDistribution $distribution): RfqDistribution
    {
        try {
            $distribution->withdraw();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'distribution' => $exception->getMessage(),
            ]);
        }

        return $distribution->fresh(['supplierProfile', 'supplierCompany']);
    }
}
