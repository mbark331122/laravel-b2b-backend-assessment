<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_supplier_company_can_have_a_supplier_profile(): void
    {
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        $this->assertNotNull($supplier->company->supplierProfile);
        $this->assertTrue($supplier->company->isSupplier());

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier-profile')
            ->assertOk()
            ->assertJsonPath('supplier_profile.company_id', $supplier->company_id);
    }

    public function test_non_supplier_company_cannot_create_supplier_profile(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/supplier-profile', [
                'display_name' => 'Fake Supplier',
                'company_id' => Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->value('id'),
            ])
            ->assertForbidden();

        $this->assertNull($buyer->company->fresh()->supplierProfile);
    }

    public function test_unauthorized_user_cannot_create_or_update_supplier_profile(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $profile = SupplierProfile::query()->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/supplier-profile', [
                'display_name' => 'No Access',
            ])
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/supplier-profiles/'.$profile->id, [
                'display_name' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $profile->fresh()->display_name);
    }

    public function test_supplier_can_create_profile_when_missing_and_ownership_ignores_client_company_id(): void
    {
        $company = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->firstOrFail();
        $company->supplierProfile?->products()->delete();
        $company->supplierProfile?->delete();

        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $otherCompanyId = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY_B)->value('id');

        $response = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier-profile', [
                'display_name' => 'Fresh Profile',
                'description' => 'New supplier profile',
                'company_id' => $otherCompanyId,
                'supplier_id' => 999,
                'tenant_id' => 999,
            ])
            ->assertCreated();

        $this->assertSame($supplier->company_id, $response->json('supplier_profile.company_id'));
        $this->assertDatabaseHas('supplier_profiles', [
            'company_id' => $supplier->company_id,
            'display_name' => 'Fresh Profile',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::SUPPLIER_PROFILE_CREATED,
            'actor_id' => $supplier->id,
            'company_id' => $supplier->company_id,
        ]);
    }

    public function test_supplier_cannot_update_another_suppliers_profile(): void
    {
        $supplierA = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $profileB = User::query()->where('email', UserSeeder::SUPPLIER_USER_B_EMAIL)->firstOrFail()
            ->company->supplierProfile;

        $this->actingAs($supplierA, 'sanctum')
            ->putJson('/api/supplier-profiles/'.$profileB->id, [
                'display_name' => 'Stolen',
            ])
            ->assertNotFound();

        $this->assertNotSame('Stolen', $profileB->fresh()->display_name);
    }
}
