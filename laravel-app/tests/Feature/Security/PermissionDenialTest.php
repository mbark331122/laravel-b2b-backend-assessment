<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use Database\Seeders\SupplierSeeder;

class PermissionDenialTest extends SecurityTestCase
{
    public function test_user_without_rfq_read_cannot_read_rfq(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        $user = $this->userWithoutPermissions($this->userA()->company, 'deny.rfq.read@example.com');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/rfqs')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/rfqs/'.$rfq->id)
            ->assertForbidden();
    }

    public function test_user_without_rfq_create_cannot_create_rfq(): void
    {
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.rfq.create@example.com',
            [Permission::RFQ_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('rfqs', 0);
    }

    public function test_user_without_rfq_update_cannot_update_rfq(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.rfq.update@example.com',
            [Permission::RFQ_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/rfqs/'.$rfq->id, $this->rfqPayload([
                'destination' => 'Riyadh',
            ]))
            ->assertForbidden();

        $this->assertSame('Jeddah', $rfq->fresh()->destination);
    }

    public function test_user_without_rfq_approve_cannot_approve_rfq_proposal(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.rfq.approve@example.com',
            [Permission::RFQ_READ, Permission::RFQ_UPDATE]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve')
            ->assertForbidden();

        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_user_without_bank_read_cannot_read_bank_data(): void
    {
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.bank.read@example.com',
            [Permission::RFQ_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierA()->id.'/bank-account')
            ->assertForbidden();
    }

    public function test_user_without_bank_change_request_cannot_create_bank_change_request(): void
    {
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.bank.change@example.com',
            [Permission::BANK_READ]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/suppliers/'.$this->supplierA()->id.'/bank-change-requests', [
                'proposed_iban' => self::PROPOSED_IBAN,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('bank_change_requests', 0);
        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
    }

    public function test_user_without_bank_approve_cannot_approve_bank_change_request(): void
    {
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());
        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'deny.bank.approve@example.com',
            [Permission::BANK_READ, Permission::BANK_CHANGE_REQUEST]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve')
            ->assertForbidden();

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertSame('pending', $changeRequest->fresh()->status);
    }
}
