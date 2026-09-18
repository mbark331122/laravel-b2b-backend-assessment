<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\BrandSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductCategorySeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfqDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_discovery_returns_eligible_suppliers_with_filters_and_ordering(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $sugar = ProductCategory::query()->where('name', ProductCategorySeeder::SUGAR)->firstOrFail();
        $grains = ProductCategory::query()->where('name', ProductCategorySeeder::GRAINS)->firstOrFail();
        $brand = Brand::query()->where('name', BrandSeeder::AL_OSRA)->firstOrFail();

        $inactive = SupplierProfile::query()
            ->where('display_name', 'Supplier Company B Trading')
            ->firstOrFail();
        $inactive->status = SupplierProfile::STATUS_INACTIVE;
        $inactive->save();

        $response = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles')
            ->assertOk();

        $names = collect($response->json('supplier_profiles'))->pluck('display_name')->all();
        $this->assertContains('Supplier Company Trading', $names);
        $this->assertNotContains('Supplier Company B Trading', $names);
        $this->assertSame($names, collect($names)->sort()->values()->all());

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles?product_category_id='.$sugar->id)
            ->assertOk()
            ->assertJsonCount(1, 'supplier_profiles')
            ->assertJsonPath('supplier_profiles.0.display_name', 'Supplier Company Trading');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles?product_category_id='.$grains->id)
            ->assertOk()
            ->assertJsonCount(0, 'supplier_profiles');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles?brand_id='.$brand->id.'&product_q=ICUMSA')
            ->assertOk()
            ->assertJsonCount(1, 'supplier_profiles');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/supplier-profiles?q=Trading')
            ->assertOk()
            ->assertJsonCount(1, 'supplier_profiles');
    }

    public function test_buyer_discovery_excludes_buyer_companies_and_non_suppliers(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $buyerCompany = Company::query()->where('name', CompanySeeder::COMPANY_B)->firstOrFail();

        $fake = new SupplierProfile;
        $fake->company()->associate($buyerCompany);
        $fake->display_name = 'Fake Buyer Profile';
        $fake->description = 'Should not appear';
        $fake->status = SupplierProfile::STATUS_ACTIVE;
        $fake->save();

        $names = collect(
            $this->actingAs($buyer, 'sanctum')
                ->getJson('/api/supplier-profiles')
                ->assertOk()
                ->json('supplier_profiles')
        )->pluck('display_name')->all();

        $this->assertNotContains('Fake Buyer Profile', $names);
    }

    public function test_match_and_distribute_submitted_rfq_to_catalog_matched_supplier(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $profileB = SupplierProfile::query()->where('display_name', 'Supplier Company B Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $match = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertOk()
            ->assertJsonPath('rfq_id', $rfqId);

        $matchedIds = collect($match->json('suppliers'))->pluck('id')->all();
        $this->assertContains($profileA->id, $matchedIds);
        $this->assertNotContains($profileB->id, $matchedIds);

        $matchedNames = collect($match->json('suppliers'))->pluck('display_name')->all();
        $this->assertSame($matchedNames, collect($matchedNames)->sort()->values()->all());

        $this->assertFalse(collect($match->json('suppliers'))->contains(fn ($row) => array_key_exists('score', $row)));
        $this->assertFalse(collect($match->json('suppliers'))->contains(fn ($row) => array_key_exists('rank', $row)));

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
                'company_id' => 999,
                'supplier_company_id' => 999,
                'tenant_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'distributions')
            ->assertJsonPath('distributions.0.status', RfqDistribution::STATUS_SENT)
            ->assertJsonPath('distributions.0.supplier_profile_id', $profileA->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RFQ_DISTRIBUTED,
            'company_id' => $buyer->company_id,
            'actor_id' => $buyer->id,
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/distributions')
            ->assertOk()
            ->assertJsonCount(1, 'distributions');
    }

    public function test_draft_cancelled_and_closed_rfqs_cannot_be_distributed(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $draftId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Draft',
                'commodity' => 'Sugar',
                'specification' => 'x',
                'quantity' => 10,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'items' => [['product_id' => $product->id, 'quantity' => 10]],
            ])
            ->assertCreated()
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$draftId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertUnprocessable();

        $submittedId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$submittedId.'/cancel', ['reason' => 'stop'])
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$submittedId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertUnprocessable();

        $closedId = $this->createSubmittedRfqWithProduct($buyer, $product);
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$closedId.'/close', ['reason' => 'done'])
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$closedId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertUnprocessable();
    }

    public function test_ineligible_and_duplicate_distributions_are_rejected(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $profileB = SupplierProfile::query()->where('display_name', 'Supplier Company B Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileB->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_profile_ids']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_profile_ids']);
    }

    public function test_withdraw_and_reactivate_distribution(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertCreated()
            ->json('distributions.0.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw', [
                'reason' => 'changed mind',
            ])
            ->assertOk()
            ->assertJsonPath('distribution.status', RfqDistribution::STATUS_WITHDRAWN);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RFQ_DISTRIBUTION_WITHDRAWN,
            'company_id' => $buyer->company_id,
        ]);

        $this->assertDatabaseCount('rfq_distributions', 1);

        $reactivatedId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertCreated()
            ->assertJsonPath('distributions.0.status', RfqDistribution::STATUS_SENT)
            ->json('distributions.0.id');

        $this->assertSame($distributionId, $reactivatedId);
        $this->assertDatabaseCount('rfq_distributions', 1);
    }

    public function test_supplier_can_read_only_distributed_rfqs(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplierA = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $supplierB = User::query()->where('email', UserSeeder::SUPPLIER_USER_B_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonCount(0, 'rfqs');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertCreated();

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonCount(1, 'rfqs')
            ->assertJsonPath('rfqs.0.id', $rfqId)
            ->assertJsonMissingPath('rfqs.0.company_id');

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/supplier/rfqs/'.$rfqId)
            ->assertOk()
            ->assertJsonPath('rfq.id', $rfqId)
            ->assertJsonPath('rfq.distribution.status', RfqDistribution::STATUS_SENT);

        $this->actingAs($supplierB, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonCount(0, 'rfqs');

        $this->actingAs($supplierB, 'sanctum')
            ->getJson('/api/supplier/rfqs/'.$rfqId)
            ->assertNotFound();

        $this->actingAs($supplierA, 'sanctum')->getJson('/api/rfqs')->assertForbidden();
        $this->actingAs($supplierA, 'sanctum')->getJson('/api/rfqs/'.$rfqId)->assertForbidden();
        $this->actingAs($supplierA, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertForbidden();
        $this->actingAs($supplierA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertForbidden();
    }

    public function test_withdrawn_distribution_hides_rfq_from_supplier_inbox(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplierA = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);
        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->json('distributions.0.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw')
            ->assertOk();

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonCount(0, 'rfqs');

        $this->actingAs($supplierA, 'sanctum')
            ->getJson('/api/supplier/rfqs/'.$rfqId)
            ->assertNotFound();
    }

    public function test_distribution_permissions_are_enforced(): void
    {
        $buyerCompany = Company::query()->where('name', CompanySeeder::COMPANY_A)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $limited = $this->userWithoutPermissions(
            $buyerCompany,
            'limited.dist@example.com',
            [Permission::RFQ_READ, Permission::RFQ_CREATE, Permission::RFQ_SUBMIT, Permission::PRODUCT_READ]
        );

        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertForbidden();
    }

    private function createSubmittedRfqWithProduct(User $buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Sugar RFQ',
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1000,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_SUBMITTED);

        return $rfqId;
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function userWithoutPermissions(Company $company, string $email, array $permissionNames = []): User
    {
        $role = \App\Models\Role::query()->create(['name' => 'limited-'.$email]);
        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user = new User;
        $user->name = 'Limited';
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }
}
