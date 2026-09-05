<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_authorized_user_can_read_bank_information(): void
    {
        $userA = $this->userA();
        $supplierA = $this->supplierA();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/suppliers/'.$supplierA->id.'/bank-account')
            ->assertOk()
            ->assertJsonPath('bank_account.iban', SupplierSeeder::SUPPLIER_A_IBAN)
            ->assertJsonPath('bank_account.beneficiary_name', 'Company A Beneficiary')
            ->assertJsonPath('bank_account.bank_name', 'Bank A')
            ->assertJsonPath('bank_account.status', SupplierBankAccount::STATUS_ACTIVE)
            ->assertJsonPath('bank_account.supplier_id', $supplierA->id);
    }

    public function test_user_without_bank_read_cannot_read_bank_information(): void
    {
        $supplierA = $this->supplierA();
        $user = $this->companyUserWithoutBankPermissions($this->userA()->company);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers/'.$supplierA->id.'/bank-account')
            ->assertForbidden();
    }

    public function test_company_a_cannot_access_company_b_bank_information(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierB()->id.'/bank-account')
            ->assertNotFound()
            ->assertJsonMissing(['bank_account']);
    }

    public function test_admin_can_read_bank_information_across_companies(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierB()->id.'/bank-account')
            ->assertOk()
            ->assertJsonPath('bank_account.iban', SupplierSeeder::SUPPLIER_B_IBAN);
    }

    public function test_direct_iban_update_endpoints_do_not_exist(): void
    {
        $supplierA = $this->supplierA();
        $account = $supplierA->bankAccount;

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/suppliers/'.$supplierA->id.'/bank-account', [
                'iban' => 'SA00DIRECTUPDATE00000001',
            ])
            ->assertMethodNotAllowed();

        $this->actingAs($this->userA(), 'sanctum')
            ->patchJson('/api/bank-accounts/'.$account->id, [
                'iban' => 'SA00DIRECTUPDATE00000001',
            ])
            ->assertNotFound();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $account->fresh()->iban);
    }

    public function test_spoofed_supplier_id_cannot_read_another_company_bank_account(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierB()->id.'/bank-account?company_id='.$this->userA()->company_id)
            ->assertNotFound();

        $this->assertDatabaseHas('supplier_bank_accounts', [
            'supplier_id' => $this->supplierB()->id,
            'iban' => SupplierSeeder::SUPPLIER_B_IBAN,
        ]);
    }

    private function userA(): User
    {
        return User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
    }

    private function supplierA(): Supplier
    {
        return Supplier::query()->where('name', SupplierSeeder::SUPPLIER_A)->firstOrFail();
    }

    private function supplierB(): Supplier
    {
        return Supplier::query()->where('name', SupplierSeeder::SUPPLIER_B)->firstOrFail();
    }

    private function companyUserWithoutBankPermissions($company): User
    {
        $role = Role::query()->create(['name' => 'no_bank']);
        $role->permissions()->sync(
            Permission::query()->whereIn('name', [Permission::RFQ_READ])->pluck('id')
        );

        $user = new User;
        $user->name = 'No Bank User';
        $user->email = 'no.bank@example.com';
        $user->password = UserSeeder::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }
}
