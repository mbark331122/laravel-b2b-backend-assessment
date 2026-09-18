<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductCategorySeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        $categoryId = ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->value('id');

        return [
            'name' => 'Refined Sugar 45',
            'sku' => 'SUG-NEW-01',
            'description' => 'Wholesale refined sugar',
            'product_category_id' => $categoryId,
            'unit' => 'MT',
            'minimum_order_quantity' => 1000,
            'wholesale_price' => 455.50,
            'currency' => 'USD',
            'status' => Product::STATUS_ACTIVE,
            ...$overrides,
        ];
    }

    public function test_authorized_supplier_can_create_product_with_authenticated_ownership(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $otherCompanyId = User::query()->where('email', UserSeeder::SUPPLIER_USER_B_EMAIL)->value('company_id');

        $response = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', $this->productPayload([
                'company_id' => $otherCompanyId,
                'supplier_id' => 999,
                'tenant_id' => 999,
                'supplier_profile_id' => 999,
            ]))
            ->assertCreated()
            ->assertJsonPath('product.company_id', $supplier->company_id)
            ->assertJsonPath('product.supplier_profile_id', $supplier->company->supplierProfile->id);

        $this->assertDatabaseHas('products', [
            'id' => $response->json('product.id'),
            'company_id' => $supplier->company_id,
            'sku' => 'SUG-NEW-01',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PRODUCT_CREATED,
            'actor_id' => $supplier->id,
            'company_id' => $supplier->company_id,
        ]);
    }

    public function test_buyer_cannot_create_product(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/products', $this->productPayload())
            ->assertForbidden();
    }

    public function test_supplier_can_list_view_update_and_delete_own_products(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $ownProduct = Product::query()->where('company_id', $supplier->company_id)->where('status', Product::STATUS_ACTIVE)->firstOrFail();

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownProduct->id]);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/products/'.$ownProduct->id)
            ->assertOk()
            ->assertJsonPath('product.id', $ownProduct->id);

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$ownProduct->id, [
                'name' => 'Updated Sugar',
                'status' => Product::STATUS_INACTIVE,
                'company_id' => 999,
            ])
            ->assertOk()
            ->assertJsonPath('product.name', 'Updated Sugar')
            ->assertJsonPath('product.status', Product::STATUS_INACTIVE)
            ->assertJsonPath('product.company_id', $supplier->company_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PRODUCT_UPDATED,
            'auditable_id' => $ownProduct->id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->deleteJson('/api/products/'.$ownProduct->id)
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $ownProduct->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PRODUCT_DELETED,
            'company_id' => $supplier->company_id,
        ]);
    }

    public function test_supplier_cannot_manage_another_suppliers_products(): void
    {
        $supplierA = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $productB = Product::query()
            ->where('company_id', User::query()->where('email', UserSeeder::SUPPLIER_USER_B_EMAIL)->value('company_id'))
            ->firstOrFail();

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/products/'.$productB->id)
            ->assertNotFound();

        $this->actingAs($supplierA, 'sanctum')
            ->putJson('/api/products/'.$productB->id, [
                'name' => 'Hijacked',
                'company_id' => $supplierA->company_id,
            ])
            ->assertNotFound();

        $this->actingAs($supplierA, 'sanctum')
            ->deleteJson('/api/products/'.$productB->id)
            ->assertNotFound();

        $this->assertSame('Wheat Grade A', $productB->fresh()->name);
        $this->assertDatabaseHas('products', ['id' => $productB->id]);
    }

    public function test_buyer_can_browse_and_view_active_products_only(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $active = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $inactive = Product::query()->where('status', Product::STATUS_INACTIVE)->firstOrFail();

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products')
            ->assertOk();

        $ids = collect($response->json('products'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$active->id)
            ->assertOk()
            ->assertJsonPath('product.id', $active->id);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$inactive->id)
            ->assertNotFound();
    }

    public function test_buyer_cannot_update_or_delete_products(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_ACTIVE)->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/products/'.$product->id, ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->deleteJson('/api/products/'.$product->id)
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
    }

    public function test_product_validation_rejects_invalid_catalog_fields(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', $this->productPayload([
                'name' => null,
                'sku' => null,
                'product_category_id' => 999999,
                'minimum_order_quantity' => -5,
                'wholesale_price' => -10,
                'currency' => 'XXX',
                'status' => 'archived',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'sku',
                'product_category_id',
                'minimum_order_quantity',
                'wholesale_price',
                'currency',
                'status',
            ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', $this->productPayload([
                'minimum_order_quantity' => 0,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minimum_order_quantity']);
    }

    public function test_supplier_can_list_own_inactive_products_but_not_other_suppliers(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $inactiveOwn = Product::query()
            ->where('company_id', $supplier->company_id)
            ->where('status', Product::STATUS_INACTIVE)
            ->firstOrFail();
        $otherActive = Product::query()
            ->where('company_id', '!=', $supplier->company_id)
            ->where('status', Product::STATUS_ACTIVE)
            ->firstOrFail();

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/products')
            ->assertOk();

        $ids = collect($response->json('products'))->pluck('id');
        $this->assertTrue($ids->contains($inactiveOwn->id));
        $this->assertFalse($ids->contains($otherActive->id));
    }
}
