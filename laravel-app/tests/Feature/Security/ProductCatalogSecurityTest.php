<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\ProductCategorySeeder;

class ProductCatalogSecurityTest extends SecurityTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return [
            'name' => 'Secure Product',
            'sku' => 'SEC-'.uniqid(),
            'description' => 'Security test product',
            'product_category_id' => ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->value('id'),
            'unit' => 'MT',
            'minimum_order_quantity' => 100,
            'wholesale_price' => 10.00,
            'currency' => 'USD',
            ...$overrides,
        ];
    }

    public function test_missing_product_permissions_are_denied(): void
    {
        $company = $this->supplierUser()->company;
        $product = Product::query()->where('company_id', $company->id)->firstOrFail();

        $noRead = $this->userWithoutPermissions($company, 'no.product.read@example.com');
        $this->actingAs($noRead, 'sanctum')->getJson('/api/products')->assertForbidden();
        $this->actingAs($noRead, 'sanctum')->getJson('/api/products/'.$product->id)->assertForbidden();

        $readOnly = $this->userWithoutPermissions($company, 'product.read.only@example.com', [Permission::PRODUCT_READ]);
        // userWithoutPermissions creates company_user-like role without supplier classification change —
        // company is supplier, so isSupplierUser() is true, but create permission missing.
        $this->actingAs($readOnly, 'sanctum')
            ->postJson('/api/products', $this->productPayload())
            ->assertForbidden();

        $this->actingAs($readOnly, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($readOnly, 'sanctum')
            ->deleteJson('/api/products/'.$product->id)
            ->assertForbidden();
    }

    public function test_permission_without_ownership_cannot_bypass_tenant_isolation(): void
    {
        $attacker = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'cross.tenant.product@example.com',
            [
                Permission::PRODUCT_READ,
                Permission::PRODUCT_CREATE,
                Permission::PRODUCT_UPDATE,
                Permission::PRODUCT_DELETE,
            ]
        );

        $victimProduct = Product::query()
            ->where('company_id', $this->supplierUserB()->company_id)
            ->firstOrFail();

        $this->actingAs($attacker, 'sanctum')
            ->getJson('/api/products/'.$victimProduct->id)
            ->assertNotFound();

        $this->actingAs($attacker, 'sanctum')
            ->putJson('/api/products/'.$victimProduct->id, [
                'name' => 'Owned Now',
                'company_id' => $attacker->company_id,
                'supplier_id' => $attacker->company_id,
                'tenant_id' => $attacker->company_id,
            ])
            ->assertNotFound();

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson('/api/products/'.$victimProduct->id.'?company_id='.$attacker->company_id)
            ->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $victimProduct->id,
            'company_id' => $this->supplierUserB()->company_id,
            'name' => $victimProduct->name,
        ]);
    }

    public function test_buyer_with_forged_create_permission_still_cannot_create_products(): void
    {
        $buyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'buyer.forged.product@example.com',
            [
                Permission::PRODUCT_READ,
                Permission::PRODUCT_CREATE,
                Permission::PRODUCT_UPDATE,
                Permission::PRODUCT_DELETE,
            ]
        );

        $this->assertTrue($buyer->isBuyerUser());
        $this->assertTrue($buyer->hasPermission(Permission::PRODUCT_CREATE));

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/products', $this->productPayload([
                'company_id' => $this->supplierUser()->company_id,
            ]))
            ->assertForbidden();

        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['name' => 'Buyer Edit'])
            ->assertForbidden();
    }

    public function test_product_id_and_ownership_fields_cannot_bypass_isolation(): void
    {
        $supplierA = $this->supplierUser();
        $productB = Product::query()
            ->where('company_id', $this->supplierUserB()->company_id)
            ->firstOrFail();

        $created = $this->actingAs($supplierA, 'sanctum')
            ->postJson('/api/products', $this->productPayload([
                'sku' => 'ISO-A-1',
                'company_id' => $productB->company_id,
                'supplier_profile_id' => $productB->supplier_profile_id,
                'tenant_id' => $productB->company_id,
            ]))
            ->assertCreated()
            ->json('product');

        $this->assertSame($supplierA->company_id, $created['company_id']);
        $this->assertSame($supplierA->company->supplierProfile->id, $created['supplier_profile_id']);
        $this->assertNotSame($productB->company_id, $created['company_id']);
    }
}
