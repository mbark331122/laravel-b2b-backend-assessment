<?php

namespace Tests\Feature\Security;

use Database\Seeders\SupplierSeeder;

class CompanyIdManipulationTest extends SecurityTestCase
{
    public function test_user_a_cannot_create_an_rfq_under_company_b_by_sending_company_id(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $userB->company_id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('rfq.company_id', $userA->company_id);

        $this->assertDatabaseHas('rfqs', [
            'id' => $response->json('rfq.id'),
            'company_id' => $userA->company_id,
        ]);
        $this->assertDatabaseMissing('rfqs', [
            'company_id' => $userB->company_id,
        ]);
    }

    public function test_user_a_cannot_move_an_rfq_to_company_b_on_update(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->putJson('/api/rfqs/'.$rfq->id, $this->rfqPayload([
                'company_id' => $this->userB()->company_id,
                'destination' => 'Yanbu',
            ]))
            ->assertOk()
            ->assertJsonPath('rfq.company_id', $userA->company_id)
            ->assertJsonPath('rfq.destination', 'Yanbu');

        $this->assertSame($userA->company_id, $rfq->fresh()->company_id);
    }

    public function test_bank_change_request_ignores_client_company_and_supplier_ids(): void
    {
        $supplierA = $this->supplierA();
        $supplierB = $this->supplierB();

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

        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $supplierA->bankAccount->fresh()->iban);
        $this->assertSame(SupplierSeeder::SUPPLIER_B_IBAN, $supplierB->bankAccount->fresh()->iban);
    }
}
