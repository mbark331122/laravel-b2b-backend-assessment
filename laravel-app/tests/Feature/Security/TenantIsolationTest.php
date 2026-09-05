<?php

namespace Tests\Feature\Security;

use Database\Seeders\SupplierSeeder;

class TenantIsolationTest extends SecurityTestCase
{
    public function test_user_a_cannot_access_company_b_rfq(): void
    {
        $rfqB = $this->createOfficialRfq($this->userB()->company);

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqB->id)
            ->assertNotFound()
            ->assertJsonMissing(['rfq']);

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqB->id,
            'company_id' => $this->userB()->company_id,
        ]);
    }

    public function test_user_b_cannot_access_company_a_rfq(): void
    {
        $rfqA = $this->createOfficialRfq($this->userA()->company);

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqA->id)
            ->assertNotFound()
            ->assertJsonMissing(['rfq']);

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqA->id,
            'company_id' => $this->userA()->company_id,
        ]);
    }

    public function test_user_a_cannot_access_company_b_bank_information(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierB()->id.'/bank-account')
            ->assertNotFound()
            ->assertJsonMissing(['bank_account']);

        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $this->supplierB()->bankAccount->fresh()->iban);
    }

    public function test_user_b_cannot_access_company_a_bank_information(): void
    {
        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierA()->id.'/bank-account')
            ->assertNotFound()
            ->assertJsonMissing(['bank_account']);

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
    }

    public function test_changing_resource_ids_does_not_bypass_tenant_isolation(): void
    {
        $rfqA = $this->createOfficialRfq($this->userA()->company);
        $rfqB = $this->createOfficialRfq($this->userB()->company);
        $changeRequestB = $this->createPendingBankChange($this->supplierB(), $this->userB());

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/rfqs/'.$rfqB->id, $this->rfqPayload([
                'destination' => 'Hijacked',
                'company_id' => $this->userA()->company_id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqB->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
                'rfq_id' => $rfqA->id,
            ])
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequestB->id.'/approve', [
                'change_request_id' => $changeRequestB->id,
                'company_id' => $this->userA()->company_id,
            ])
            ->assertForbidden();

        $this->assertSame('Jeddah', $rfqB->fresh()->destination);
        $this->assertSame($this->userB()->company_id, $rfqB->fresh()->company_id);
        $this->assertDatabaseCount('ai_extractions', 0);
        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $this->supplierB()->bankAccount->fresh()->iban);
        $this->assertSame('pending', $changeRequestB->fresh()->status);
    }
}
