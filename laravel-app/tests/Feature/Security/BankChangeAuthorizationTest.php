<?php

namespace Tests\Feature\Security;

use App\Models\BankChangeRequest;
use App\Models\Permission;
use Database\Seeders\SupplierSeeder;

class BankChangeAuthorizationTest extends SecurityTestCase
{
    public function test_unauthorized_user_cannot_create_a_bank_change_request(): void
    {
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.bank.change@example.com',
            [Permission::BANK_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/suppliers/'.$this->supplierA()->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
            ])
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseCount('bank_change_requests', 0);
    }

    public function test_normal_user_change_request_leaves_official_iban_unchanged(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/suppliers/'.$this->supplierA()->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
            ])
            ->assertCreated()
            ->assertJsonPath('change_request.status', BankChangeRequest::STATUS_PENDING)
            ->assertJsonPath('bank_account.iban', SupplierSeeder::SUPPLIER_A_IBAN);

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseCount('bank_account_histories', 0);
    }

    public function test_unauthorized_user_cannot_approve_bank_change(): void
    {
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertSame(BankChangeRequest::STATUS_PENDING, $changeRequest->fresh()->status);
    }

    public function test_authorized_approval_updates_iban_and_preserves_history(): void
    {
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve', [
                'iban' => 'SA00IGNORED0000000000001',
            ])
            ->assertOk()
            ->assertJsonPath('bank_account.iban', self::PROPOSED_IBAN)
            ->assertJsonPath('bank_account.history.0.iban', SupplierSeeder::SUPPLIER_A_IBAN);

        $this->assertSame(self::PROPOSED_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseHas('bank_account_histories', [
            'supplier_bank_account_id' => $this->supplierA()->bankAccount->id,
            'iban' => SupplierSeeder::SUPPLIER_A_IBAN,
        ]);
    }
}
