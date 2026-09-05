<?php

namespace Tests\Feature\Security;

use App\Models\RfqProposal;

class RfqApprovalSecurityTest extends SecurityTestCase
{
    public function test_pending_proposal_leaves_official_rfq_unchanged(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->assertSame(RfqProposal::STATUS_PENDING, $proposal->status);
        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
        $this->assertSame($this->userA()->company_id, $proposal->rfq->fresh()->company_id);
    }

    public function test_rejected_proposal_leaves_official_rfq_unchanged(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/reject', [
                'reason' => 'Quantity looks wrong',
            ])
            ->assertOk()
            ->assertJsonPath('proposal.status', RfqProposal::STATUS_REJECTED)
            ->assertJsonPath('rfq.quantity', 25000);

        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
    }

    public function test_approved_proposal_updates_official_rfq_from_stored_value(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve', [
                'proposed_value' => '1',
                'quantity' => 1,
                'reason' => 'Confirmed with supplier',
            ])
            ->assertOk()
            ->assertJsonPath('rfq.quantity', 50000)
            ->assertJsonPath('proposal.proposed_value', '50000');

        $this->assertSame(50000, $proposal->rfq->fresh()->quantity);
        $this->assertSame($this->userA()->company_id, $proposal->rfq->fresh()->company_id);
    }
}
