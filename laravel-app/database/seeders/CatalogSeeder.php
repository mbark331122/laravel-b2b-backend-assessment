<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPriceTier;
use App\Models\ProductSpecification;
use App\Models\SupplierProfile;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $sugar = ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->firstOrFail();
        $grains = ProductCategory::query()->where('name', ProductCategorySeeder::GRAINS)->firstOrFail();
        $brandSugar = Brand::query()->where('name', BrandSeeder::AL_OSRA)->firstOrFail();
        $brandGrain = Brand::query()->where('name', BrandSeeder::GRAINCO)->firstOrFail();

        $supplierA = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->firstOrFail();
        $supplierB = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY_B)->firstOrFail();

        $profileA = $this->seedProfile($supplierA, 'Supplier Company Trading', 'Wholesale sugar and commodities.');
        $profileB = $this->seedProfile($supplierB, 'Supplier Company B Trading', 'Wholesale grains.');

        $published = $this->seedProduct(
            $supplierA,
            $profileA,
            $sugar,
            $brandSugar,
            'ICUMSA 45 Sugar',
            'SUG-45',
            1000,
            10000,
            100,
            '450.00',
            Product::STATUS_PUBLISHED,
        );
        $this->seedSpecs($published, [
            ['name' => 'Polarization', 'value' => '99.8', 'unit' => '%'],
            ['name' => 'Color', 'value' => 'ICUMSA 45', 'unit' => null],
        ]);
        $this->seedTiers($published, [
            ['min_quantity' => 1000, 'max_quantity' => 4999, 'price' => '450.00', 'currency' => 'USD'],
            ['min_quantity' => 5000, 'max_quantity' => 9999, 'price' => '430.00', 'currency' => 'USD'],
            ['min_quantity' => 10000, 'max_quantity' => null, 'price' => '410.00', 'currency' => 'USD'],
        ]);

        $this->seedProduct(
            $supplierA,
            $profileA,
            $sugar,
            $brandSugar,
            'Raw Cane Sugar',
            'SUG-RAW',
            500,
            null,
            50,
            '320.00',
            Product::STATUS_ARCHIVED,
        );

        $this->seedProduct(
            $supplierA,
            $profileA,
            $sugar,
            $brandSugar,
            'Draft Brown Sugar',
            'SUG-DRAFT',
            200,
            null,
            1,
            '300.00',
            Product::STATUS_DRAFT,
        );

        $wheat = $this->seedProduct(
            $supplierB,
            $profileB,
            $grains,
            $brandGrain,
            'Wheat Grade A',
            'WHT-A',
            2000,
            20000,
            100,
            '280.00',
            Product::STATUS_PUBLISHED,
        );
        $this->seedSpecs($wheat, [
            ['name' => 'Protein', 'value' => '12.5', 'unit' => '%'],
            ['name' => 'Moisture', 'value' => '12', 'unit' => '%'],
        ]);
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
        Brand $brand,
        string $name,
        string $sku,
        int $moq,
        ?int $max,
        int $increment,
        string $price,
        string $status,
    ): Product {
        $product = Product::query()->firstOrNew([
            'company_id' => $company->id,
            'sku' => $sku,
        ]);
        $product->company()->associate($company);
        $product->supplierProfile()->associate($profile);
        $product->category()->associate($category);
        $product->brand()->associate($brand);
        $product->name = $name;
        $product->description = $name.' wholesale listing.';
        $product->unit = 'MT';
        $product->minimum_order_quantity = $moq;
        $product->maximum_order_quantity = $max;
        $product->quantity_increment = $increment;
        $product->wholesale_price = $price;
        $product->currency = 'USD';
        $product->status = $status;
        $product->save();

        return $product;
    }

    /**
     * @param  list<array{name: string, value: string, unit: string|null}>  $specs
     */
    private function seedSpecs(Product $product, array $specs): void
    {
        $product->specifications()->delete();
        foreach ($specs as $index => $spec) {
            $row = new ProductSpecification;
            $row->product()->associate($product);
            $row->name = $spec['name'];
            $row->value = $spec['value'];
            $row->unit = $spec['unit'];
            $row->sort_order = $index;
            $row->save();
        }
    }

    /**
     * @param  list<array{min_quantity: int, max_quantity: int|null, price: string, currency: string}>  $tiers
     */
    private function seedTiers(Product $product, array $tiers): void
    {
        $product->priceTiers()->delete();
        foreach ($tiers as $tier) {
            $row = new ProductPriceTier;
            $row->product()->associate($product);
            $row->min_quantity = $tier['min_quantity'];
            $row->max_quantity = $tier['max_quantity'];
            $row->price = $tier['price'];
            $row->currency = $tier['currency'];
            $row->save();
        }
    }
}
