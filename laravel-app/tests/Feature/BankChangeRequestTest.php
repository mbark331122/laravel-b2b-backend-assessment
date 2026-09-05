<?php

namespace Tests\Feature;

use App\Models\BankChangeRequest;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PROPOSED_IBAN = 'SA00AAAA0000000000000099';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_authorized_user_can_create_a_bank_change_request(): void
    {
        $userA = $this->userA();
        $supplierA = $this->supplierA();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/suppliers/'.$supplierA->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
            ]);

        $response->assertCreated()
            ->assertJsonPath('change_request.current_iban', SupplierSeeder::SUPPLIER_A_IBAN)
            ->assertJsonPath('change_request.proposed_iban', self::PROPOSED_IBAN)
            ->assertJsonPath('change_request.status', BankChangeRequest::STATUS_PENDING)
            ->assertJsonPath('change_request.company_id', $userA->company_id)
            ->assertJsonPath('change_request.requested_by_user_id', $userA->id);

        $this->assertDatabaseHas('bank_change_requests', [
            'supplier_bank_account_id' => $supplierA->bankAccount->id,
            'current_iban' => SupplierSeeder::SUPPLIER_A_IBAN,
            'proposed_iban' => self::PROPOSED_IBAN,
            'status' => BankChangeRequest::STATUS_PENDING,
        ]);
    }

    public function test_creating_a_change_request_does_not_change_the_official_iban(): void
    {
        $supplierA = $this->supplierA();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/suppliers/'.$supplierA->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
            ])
            ->assertCreated()
            ->assertJsonPath('bank_account.iban', SupplierSeeder::SUPPLIER_A_IBAN);

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $supplierA->bankAccount->fresh()->iban);
        $this->assertDatabaseCount('bank_account_histories', 0);
    }

    public function test_authorized_user_can_view_a_bank_change_request(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/bank-change-requests/'.$changeRequest->id)
            ->assertOk()
            ->assertJsonPath('change_request.id', $changeRequest->id)
            ->assertJsonPath('change_request.proposed_iban', self::PROPOSED_IBAN)
            ->assertJsonPath('change_request.status', BankChangeRequest::STATUS_PENDING);
    }

    public function test_normal_user_without_bank_approve_cannot_approve(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertSame(BankChangeRequest::STATUS_PENDING, $changeRequest->fresh()->status);
    }

    public function test_admin_can_approve_and_official_iban_updates_from_stored_proposed_value(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve', [
                'proposed_iban' => 'SA00CLIENTSUPPLIED000001',
                'iban' => 'SA00CLIENTSUPPLIED000001',
            ])
            ->assertOk()
            ->assertJsonPath('change_request.status', BankChangeRequest::STATUS_APPROVED)
            ->assertJsonPath('change_request.proposed_iban', self::PROPOSED_IBAN)
            ->assertJsonPath('bank_account.iban', self::PROPOSED_IBAN);

        $this->assertSame(self::PROPOSED_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertSame(BankChangeRequest::STATUS_APPROVED, $changeRequest->fresh()->status);
    }

    public function test_approval_preserves_the_old_iban_in_history(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve')
            ->assertOk()
            ->assertJsonPath('bank_account.history.0.iban', SupplierSeeder::SUPPLIER_A_IBAN)
            ->assertJsonPath('bank_account.history.0.beneficiary_name', 'Company A Beneficiary')
            ->assertJsonPath('bank_account.history.0.bank_name', 'Bank A');

        $this->assertDatabaseHas('bank_account_histories', [
            'supplier_bank_account_id' => $this->supplierA()->bankAccount->id,
            'iban' => SupplierSeeder::SUPPLIER_A_IBAN,
            'beneficiary_name' => 'Company A Beneficiary',
            'bank_name' => 'Bank A',
        ]);

        $this->assertSame(self::PROPOSED_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
    }

    public function test_rejection_leaves_the_official_iban_unchanged(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/reject')
            ->assertOk()
            ->assertJsonPath('change_request.status', BankChangeRequest::STATUS_REJECTED)
            ->assertJsonPath('bank_account.iban', SupplierSeeder::SUPPLIER_A_IBAN);

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseCount('bank_account_histories', 0);
    }

    public function test_company_a_cannot_create_a_change_request_for_company_b(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/suppliers/'.$this->supplierB()->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
                'company_id' => $this->userA()->company_id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('bank_change_requests', 0);
        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $this->supplierB()->bankAccount->fresh()->iban);
    }

    public function test_company_a_cannot_approve_company_b_change_request(): void
    {
        $changeRequestB = $this->createPendingChange($this->supplierB(), $this->userB());

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequestB->id.'/approve')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $this->supplierB()->bankAccount->fresh()->iban);
        $this->assertSame(BankChangeRequest::STATUS_PENDING, $changeRequestB->fresh()->status);
    }

    public function test_changing_ids_cannot_bypass_tenant_isolation(): void
    {
        $supplierA = $this->supplierA();
        $supplierB = $this->supplierB();
        $changeRequestB = $this->createPendingChange($supplierB, $this->userB());

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/suppliers/'.$supplierA->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
                'company_id' => $supplierB->company_id,
                'supplier_id' => $supplierB->id,
                'bank_account_id' => $supplierB->bankAccount->id,
            ])
            ->assertCreated()
            ->assertJsonPath('change_request.company_id', $supplierA->company_id)
            ->assertJsonPath('change_request.supplier_bank_account_id', $supplierA->bankAccount->id);

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/bank-change-requests/'.$changeRequestB->id)
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequestB->id.'/approve', [
                'company_id' => $supplierA->company_id,
                'change_request_id' => $changeRequestB->id,
            ])
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $supplierB->bankAccount->fresh()->iban);
    }

    public function test_approval_ignores_client_supplied_iban_and_uses_stored_proposed_iban(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve', [
                'proposed_iban' => 'SA00HIJACKED000000000001',
                'iban' => 'SA00HIJACKED000000000001',
                'company_id' => $this->userB()->company_id,
            ])
            ->assertOk()
            ->assertJsonPath('bank_account.iban', self::PROPOSED_IBAN);

        $this->assertSame(self::PROPOSED_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseMissing('supplier_bank_accounts', [
            'iban' => 'SA00HIJACKED000000000001',
        ]);
    }

    public function test_pending_request_cannot_directly_modify_the_official_bank_account(): void
    {
        $changeRequest = $this->createPendingChange($this->supplierA(), $this->userA());

        $this->assertSame(BankChangeRequest::STATUS_PENDING, $changeRequest->status);
        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseCount('bank_account_histories', 0);
    }

    private function userA(): User
    {
        return User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
    }

    private function userB(): User
    {
        return User::query()->where('email', UserSeeder::USER_B_EMAIL)->firstOrFail();
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

    private function createPendingChange(Supplier $supplier, User $user): BankChangeRequest
    {
        $account = $supplier->bankAccount;

        $changeRequest = new BankChangeRequest;
        $changeRequest->bankAccount()->associate($account);
        $changeRequest->company()->associate($supplier->company);
        $changeRequest->requestedBy()->associate($user);
        $changeRequest->current_iban = $account->iban;
        $changeRequest->proposed_iban = self::PROPOSED_IBAN;
        $changeRequest->status = BankChangeRequest::STATUS_PENDING;
        $changeRequest->save();

        return $changeRequest;
    }
}
