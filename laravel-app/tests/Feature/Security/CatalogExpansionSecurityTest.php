<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\ProductCategorySeeder;

class CatalogExpansionSecurityTest extends SecurityTestCase
{
    public function test_nested_catalog_mutations_cannot_cross_suppliers_or_be_buyer_driven(): void
    {
        $productB = Product::query()
            ->where('company_id', $this->supplierUserB()->company_id)
            ->firstOrFail();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->putJson('/api/products/'.$productB->id.'/specifications', [
                'specifications' => [
                    ['name' => 'Steal', 'value' => 'Yes'],
                ],
                'company_id' => $this->supplierUser()->company_id,
                'supplier_id' => $this->supplierUser()->company_id,
                'tenant_id' => $this->supplierUser()->company_id,
            ])
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->putJson('/api/products/'.$productB->id.'/price-tiers', [
                'tiers' => [
                    ['min_quantity' => 1, 'max_quantity' => null, 'price' => 1, 'currency' => 'USD'],
                ],
            ])
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/products/'.$productB->id.'/transitions', [
                'status' => Product::STATUS_ARCHIVED,
            ])
            ->assertForbidden();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/products/'.$productB->id.'/transitions', [
                'status' => Product::STATUS_ARCHIVED,
            ])
            ->assertNotFound();
    }

    public function test_search_cannot_leak_unpublished_products_via_filters(): void
    {
        $draft = Product::query()->where('status', Product::STATUS_DRAFT)->firstOrFail();
        $archived = Product::query()->where('status', Product::STATUS_ARCHIVED)->firstOrFail();

        $response = $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/products?q=Sugar&company_id='.$draft->company_id.'&status=draft&supplier_id='.$draft->company_id.'&tenant_id='.$draft->company_id)
            ->assertOk();

        $ids = collect($response->json('products'))->pluck('id');
        $this->assertFalse($ids->contains($draft->id));
        $this->assertFalse($ids->contains($archived->id));
        $this->assertTrue($ids->every(function ($id) {
            return Product::query()->whereKey($id)->where('status', Product::STATUS_PUBLISHED)->exists();
        }));
    }

    public function test_buyer_cannot_create_brands_and_spoof_brand_ownership(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/brands', [
                'name' => 'Buyer Brand',
                'company_id' => $this->supplierUser()->company_id,
            ])
            ->assertForbidden();

        $categoryId = ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->value('id');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Buyer Product',
                'sku' => 'BUYER-1',
                'product_category_id' => $categoryId,
                'unit' => 'MT',
                'minimum_order_quantity' => 1,
                'wholesale_price' => 1,
                'currency' => 'USD',
            ])
            ->assertForbidden();
    }
}
