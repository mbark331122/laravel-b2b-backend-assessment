<?php

namespace Tests\Feature\Security;

use App\Models\RfqProposal;

class AiDirectUpdateProtectionTest extends SecurityTestCase
{
    public function test_ai_extraction_cannot_directly_update_the_official_rfq(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company, ['quantity' => 25000]);

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
                'quantity' => 99999,
            ]);

        $response->assertCreated()
            ->assertJsonPath('extraction.quantity', 50000)
            ->assertJsonPath('extraction.proposals.0.proposed_value', '50000')
            ->assertJsonPath('extraction.proposals.0.status', RfqProposal::STATUS_PENDING)
            ->assertJsonPath('rfq.quantity', 25000);

        $this->assertSame(25000, $rfq->fresh()->quantity);
        $this->assertDatabaseHas('rfq_proposals', [
            'rfq_id' => $rfq->id,
            'proposed_value' => '50000',
            'status' => RfqProposal::STATUS_PENDING,
        ]);
    }

    public function test_only_authorized_approval_can_change_the_official_rfq(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company, ['quantity' => 25000]);

        $extraction = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ])
            ->assertCreated();

        $proposalId = $extraction->json('extraction.proposals.0.id');
        $this->assertSame(25000, $rfq->fresh()->quantity);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/proposals/'.$proposalId.'/approve')
            ->assertForbidden();
        $this->assertSame(25000, $rfq->fresh()->quantity);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposalId.'/approve', [
                'proposed_value' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('rfq.quantity', 50000);

        $this->assertSame(50000, $rfq->fresh()->quantity);
    }
}
