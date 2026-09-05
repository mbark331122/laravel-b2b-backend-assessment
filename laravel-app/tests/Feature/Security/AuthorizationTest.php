<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use Database\Seeders\SupplierSeeder;

class AuthorizationTest extends SecurityTestCase
{
    public function test_normal_user_cannot_approve_rfq_proposal(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve')
            ->assertForbidden();

        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_normal_user_cannot_approve_bank_change(): void
    {
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertSame('pending', $changeRequest->fresh()->status);
    }

    public function test_user_without_rfq_read_cannot_access_protected_rfq_data(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        $user = $this->userWithoutPermissions($this->userA()->company, 'no.rfq.read@example.com');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/rfqs')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/rfqs/'.$rfq->id)
            ->assertForbidden();
    }

    public function test_user_without_bank_read_cannot_access_protected_bank_data(): void
    {
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.bank.read@example.com',
            [Permission::RFQ_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierA()->id.'/bank-account')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
    }
}
