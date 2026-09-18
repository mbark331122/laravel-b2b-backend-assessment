<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeded_buyer_companies_are_classified_as_buyers_only(): void
    {
        $companyA = Company::query()->where('name', CompanySeeder::COMPANY_A)->firstOrFail();
        $companyB = Company::query()->where('name', CompanySeeder::COMPANY_B)->firstOrFail();

        $this->assertTrue($companyA->isBuyer());
        $this->assertFalse($companyA->isSupplier());
        $this->assertTrue($companyB->isBuyer());
        $this->assertFalse($companyB->isSupplier());
    }

    public function test_seeded_supplier_company_is_classified_as_supplier_only(): void
    {
        $supplierCompany = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->firstOrFail();

        $this->assertFalse($supplierCompany->isBuyer());
        $this->assertTrue($supplierCompany->isSupplier());
    }

    public function test_company_classification_is_not_mass_assignable(): void
    {
        $company = Company::query()->create(['name' => 'Mass Assign Co']);

        $company->fill([
            'is_buyer' => true,
            'is_supplier' => true,
            'name' => 'Mass Assign Co',
        ]);
        $company->save();

        $this->assertFalse($company->fresh()->isBuyer());
        $this->assertFalse($company->fresh()->isSupplier());
        $this->assertSame('Mass Assign Co', $company->fresh()->name);
    }

    public function test_buyer_and_supplier_users_resolve_classification_from_their_company(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();

        $this->assertTrue($buyer->isBuyerUser());
        $this->assertFalse($buyer->isSupplierUser());
        $this->assertTrue($buyer->hasRole(Role::COMPANY_USER));

        $this->assertFalse($supplier->isBuyerUser());
        $this->assertTrue($supplier->isSupplierUser());
        $this->assertTrue($supplier->hasRole(Role::COMPANY_USER));
        $this->assertSame(CompanySeeder::SUPPLIER_COMPANY, $supplier->company->name);

        $this->assertFalse($admin->isBuyerUser());
        $this->assertFalse($admin->isSupplierUser());
        $this->assertNull($admin->company_id);
    }

    public function test_authentication_exposes_company_classification_from_server_state(): void
    {
        $this->postJson('/api/login', [
            'email' => UserSeeder::USER_A_EMAIL,
            'password' => UserSeeder::PASSWORD,
            'company_id' => 999,
            'is_buyer' => false,
            'is_supplier' => true,
        ])
            ->assertOk()
            ->assertJsonPath('user.company.name', CompanySeeder::COMPANY_A)
            ->assertJsonPath('user.company.is_buyer', true)
            ->assertJsonPath('user.company.is_supplier', false);

        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.company.name', CompanySeeder::SUPPLIER_COMPANY)
            ->assertJsonPath('user.company.is_buyer', false)
            ->assertJsonPath('user.company.is_supplier', true);
    }
}
