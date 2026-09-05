<?php

namespace Tests\Feature;

use App\Models\AiExtraction;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqProposal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfqProposalApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_normal_user_without_rfq_approve_cannot_approve(): void
    {
        $userA = $this->userA();
        $proposal = $this->createQuantityConflict($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve')
            ->assertForbidden();

        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
        $this->assertSame(RfqProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    public function test_authorized_user_can_approve_and_official_rfq_is_updated_from_stored_proposal(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve', [
                'proposed_value' => '99999',
                'quantity' => 1,
                'company_id' => $this->userB()->company_id,
            ])
            ->assertOk()
            ->assertJsonPath('proposal.status', RfqProposal::STATUS_APPROVED)
            ->assertJsonPath('proposal.proposed_value', '50000')
            ->assertJsonPath('rfq.quantity', 50000)
            ->assertJsonPath('rfq.company_id', $this->userA()->company_id);

        $this->assertSame(50000, $proposal->rfq->fresh()->quantity);
        $this->assertSame(RfqProposal::STATUS_APPROVED, $proposal->fresh()->status);
        $this->assertSame(AiExtraction::STATUS_APPROVED, $proposal->aiExtraction->fresh()->status);
    }

    public function test_rejection_does_not_change_the_official_rfq(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/reject')
            ->assertOk()
            ->assertJsonPath('proposal.status', RfqProposal::STATUS_REJECTED)
            ->assertJsonPath('rfq.quantity', 25000);

        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);
        $this->assertSame(RfqProposal::STATUS_REJECTED, $proposal->fresh()->status);
    }

    public function test_company_a_user_cannot_approve_a_company_b_proposal(): void
    {
        $proposalB = $this->createQuantityConflict($this->userB()->company);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/proposals/'.$proposalB->id.'/approve')
            ->assertForbidden();

        $this->assertSame(25000, $proposalB->rfq->fresh()->quantity);
        $this->assertSame(RfqProposal::STATUS_PENDING, $proposalB->fresh()->status);
    }

    public function test_changing_proposal_id_cannot_approve_another_company_proposal(): void
    {
        $proposalA = $this->createQuantityConflict($this->userA()->company);
        $proposalB = $this->createQuantityConflict($this->userB()->company);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/proposals/'.$proposalB->id.'/approve')
            ->assertForbidden();

        $this->assertSame(RfqProposal::STATUS_PENDING, $proposalA->fresh()->status);
        $this->assertSame(RfqProposal::STATUS_PENDING, $proposalB->fresh()->status);
        $this->assertSame(25000, $proposalA->rfq->fresh()->quantity);
        $this->assertSame(25000, $proposalB->rfq->fresh()->quantity);
    }

    public function test_admin_cannot_be_tricked_into_writing_request_body_values_onto_the_rfq(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve', [
                'field' => 'destination',
                'proposed_value' => 'Hijacked',
                'company_id' => $this->userB()->company_id,
            ])
            ->assertOk()
            ->assertJsonPath('rfq.quantity', 50000)
            ->assertJsonPath('rfq.destination', 'Jeddah')
            ->assertJsonPath('rfq.company_id', $this->userA()->company_id);
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

    private function createQuantityConflict(Company $company): RfqProposal
    {
        $rfq = $company->rfqs()->create([
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 25000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            'status' => Rfq::STATUS_DRAFT,
        ]);

        $extraction = $rfq->aiExtractions()->create([
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 50000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            'confidence' => 0.9,
            'source' => 'AI/mock',
            'status' => AiExtraction::STATUS_PENDING,
        ]);

        $proposal = new RfqProposal;
        $proposal->rfq()->associate($rfq);
        $proposal->aiExtraction()->associate($extraction);
        $proposal->field = 'quantity';
        $proposal->current_value = '25000';
        $proposal->proposed_value = '50000';
        $proposal->source = 'AI/mock';
        $proposal->confidence = 0.9;
        $proposal->status = RfqProposal::STATUS_PENDING;
        $proposal->save();

        return $proposal;
    }
}
