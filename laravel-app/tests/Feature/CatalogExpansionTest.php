<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\BrandSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_brands_are_readable_and_unique_and_assignable_to_products(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/brands')
            ->assertOk()
            ->assertJsonFragment(['name' => BrandSeeder::AL_OSRA]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/brands', [
                'name' => BrandSeeder::AL_OSRA,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/brands', [
                'name' => 'New Brand Co',
                'company_id' => $supplier->company_id,
            ])
            ->assertCreated()
            ->assertJsonPath('brand.name', 'New Brand Co')
            ->assertJsonPath('brand.slug', 'new-brand-co');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::BRAND_CREATED,
            'auditable_id' => $created->json('brand.id'),
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/brands', ['name' => 'Supplier Brand'])
            ->assertForbidden();

        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $brandId = Brand::query()->where('name', BrandSeeder::AL_OSRA)->value('id');
        $this->assertSame($brandId, $product->brand_id);
    }

    public function test_product_specifications_can_be_synced_by_owner_and_read_by_buyers(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $other = Product::query()->where('sku', 'WHT-A')->firstOrFail();

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$product->id.'/specifications', [
                'specifications' => [
                    ['name' => 'Material', 'value' => 'Cane', 'unit' => null],
                    ['name' => 'Voltage', 'value' => '220', 'unit' => 'V'],
                    ['name' => 'Capacity', 'value' => '500', 'unit' => 'L'],
                ],
                'company_id' => 999,
            ])
            ->assertOk()
            ->assertJsonCount(3, 'specifications');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$product->id.'/specifications')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Material', 'value' => 'Cane']);

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$other->id.'/specifications', [
                'specifications' => [
                    ['name' => 'Hijack', 'value' => 'Nope'],
                ],
            ])
            ->assertNotFound();

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/products/'.$product->id.'/specifications', [
                'specifications' => [],
            ])
            ->assertForbidden();
    }

    public function test_price_tiers_validate_ranges_and_respect_ownership(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $other = Product::query()->where('sku', 'WHT-A')->firstOrFail();

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$product->id.'/price-tiers', [
                'tiers' => [
                    ['min_quantity' => 10, 'max_quantity' => 49, 'price' => 100, 'currency' => 'SAR'],
                    ['min_quantity' => 50, 'max_quantity' => 99, 'price' => 90, 'currency' => 'SAR'],
                    ['min_quantity' => 100, 'max_quantity' => null, 'price' => 80, 'currency' => 'SAR'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'price_tiers');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$product->id.'/price-tiers')
            ->assertOk()
            ->assertJsonPath('price_tiers.0.price', '100.00');

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$product->id.'/price-tiers', [
                'tiers' => [
                    ['min_quantity' => 10, 'max_quantity' => 50, 'price' => 100, 'currency' => 'SAR'],
                    ['min_quantity' => 40, 'max_quantity' => 90, 'price' => 90, 'currency' => 'SAR'],
                ],
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$product->id.'/price-tiers', [
                'tiers' => [
                    ['min_quantity' => 0, 'max_quantity' => 10, 'price' => -5, 'currency' => 'XXX'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tiers.0.min_quantity', 'tiers.0.price', 'tiers.0.currency']);

        $this->actingAs($supplier, 'sanctum')
            ->putJson('/api/products/'.$other->id.'/price-tiers', [
                'tiers' => [
                    ['min_quantity' => 1, 'max_quantity' => null, 'price' => 10, 'currency' => 'USD'],
                ],
            ])
            ->assertNotFound();
    }

    public function test_quantity_constraints_are_validated(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $categoryId = Product::query()->where('sku', 'SUG-45')->value('product_category_id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Bad Qty',
                'sku' => 'BAD-QTY',
                'product_category_id' => $categoryId,
                'unit' => 'MT',
                'minimum_order_quantity' => 100,
                'maximum_order_quantity' => 50,
                'quantity_increment' => 100,
                'wholesale_price' => 10,
                'currency' => 'USD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['maximum_order_quantity']);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Bad Increment Align',
                'sku' => 'BAD-INC',
                'product_category_id' => $categoryId,
                'unit' => 'MT',
                'minimum_order_quantity' => 100,
                'maximum_order_quantity' => 10050,
                'quantity_increment' => 100,
                'wholesale_price' => 10,
                'currency' => 'USD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['maximum_order_quantity']);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products', [
                'name' => 'Good Qty',
                'sku' => 'GOOD-QTY',
                'product_category_id' => $categoryId,
                'unit' => 'MT',
                'minimum_order_quantity' => 100,
                'maximum_order_quantity' => 10000,
                'quantity_increment' => 100,
                'wholesale_price' => 10,
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('product.quantity_increment', 100)
            ->assertJsonPath('product.maximum_order_quantity', 10000);
    }

    public function test_lifecycle_transitions_and_buyer_visibility(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-DRAFT')->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/transitions', [
                'status' => Product::STATUS_PUBLISHED,
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/transitions', [
                'status' => Product::STATUS_PENDING_REVIEW,
            ])
            ->assertOk()
            ->assertJsonPath('product.status', Product::STATUS_PENDING_REVIEW);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/transitions', [
                'status' => Product::STATUS_APPROVED,
                'reason' => 'Looks good',
            ])
            ->assertOk()
            ->assertJsonPath('product.status', Product::STATUS_APPROVED);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/transitions', [
                'status' => Product::STATUS_PUBLISHED,
            ])
            ->assertOk()
            ->assertJsonPath('product.status', Product::STATUS_PUBLISHED);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products/'.$product->id)
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PRODUCT_TRANSITIONED,
            'auditable_id' => $product->id,
        ]);
    }

    public function test_catalog_search_and_supplier_discovery_filters(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $published = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $draft = Product::query()->where('sku', 'SUG-DRAFT')->firstOrFail();

        $byName = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?q=ICUMSA')
            ->assertOk();
        $this->assertTrue(collect($byName->json('products'))->pluck('id')->contains($published->id));
        $this->assertFalse(collect($byName->json('products'))->pluck('id')->contains($draft->id));

        $bySku = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?q=SUG-45')
            ->assertOk();
        $this->assertCount(1, $bySku->json('products'));

        $byBrand = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?brand_id='.$published->brand_id)
            ->assertOk();
        $this->assertTrue(collect($byBrand->json('products'))->every(fn ($p) => $p['brand_id'] === $published->brand_id));

        $byCategory = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?product_category_id='.$published->product_category_id)
            ->assertOk();
        $this->assertNotEmpty($byCategory->json('products'));

        $bySupplier = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?supplier_profile_id='.$published->supplier_profile_id)
            ->assertOk();
        $this->assertTrue(collect($bySupplier->json('products'))->every(
            fn ($p) => $p['supplier_profile_id'] === $published->supplier_profile_id
        ));

        $byMoq = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?min_moq=1500')
            ->assertOk();
        $this->assertTrue(collect($byMoq->json('products'))->every(fn ($p) => $p['minimum_order_quantity'] >= 1500));

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?status=draft')
            ->assertOk()
            ->assertJsonMissing(['sku' => 'SUG-DRAFT']);

        $profiles = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles?q=Trading')
            ->assertOk();
        $this->assertGreaterThanOrEqual(1, count($profiles->json('supplier_profiles')));
    }
}
