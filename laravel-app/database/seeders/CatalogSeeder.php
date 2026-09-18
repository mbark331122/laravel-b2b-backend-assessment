<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SupplierProfile;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $sugar = ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->firstOrFail();
        $grains = ProductCategory::query()->where('name', ProductCategorySeeder::GRAINS)->firstOrFail();

        $supplierA = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->firstOrFail();
        $supplierB = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY_B)->firstOrFail();

        $profileA = $this->seedProfile($supplierA, 'Supplier Company Trading', 'Wholesale sugar and commodities.');
        $profileB = $this->seedProfile($supplierB, 'Supplier Company B Trading', 'Wholesale grains.');

        $this->seedProduct($supplierA, $profileA, $sugar, 'ICUMSA 45 Sugar', 'SUG-45', 1000, '450.00', Product::STATUS_ACTIVE);
        $this->seedProduct($supplierA, $profileA, $sugar, 'Raw Cane Sugar', 'SUG-RAW', 500, '320.00', Product::STATUS_INACTIVE);
        $this->seedProduct($supplierB, $profileB, $grains, 'Wheat Grade A', 'WHT-A', 2000, '280.00', Product::STATUS_ACTIVE);
    }

    private function seedProfile(Company $company, string $displayName, string $description): SupplierProfile
    {
        $profile = $company->supplierProfile ?: new SupplierProfile;
        $profile->company()->associate($company);
        $profile->display_name = $displayName;
        $profile->description = $description;
        $profile->contact_email = strtolower(str_replace(' ', '.', $company->name)).'@example.com';
        $profile->contact_phone = '+966500000000';
        $profile->status = SupplierProfile::STATUS_ACTIVE;
        $profile->save();

        return $profile;
    }

    private function seedProduct(
        Company $company,
        SupplierProfile $profile,
        ProductCategory $category,
        string $name,
        string $sku,
        int $moq,
        string $price,
        string $status,
    ): void {
        $product = Product::query()->firstOrNew([
            'company_id' => $company->id,
            'sku' => $sku,
        ]);
        $product->company()->associate($company);
        $product->supplierProfile()->associate($profile);
        $product->category()->associate($category);
        $product->name = $name;
        $product->description = $name.' wholesale listing.';
        $product->unit = 'MT';
        $product->minimum_order_quantity = $moq;
        $product->wholesale_price = $price;
        $product->currency = 'USD';
        $product->status = $status;
        $product->save();
    }
}
