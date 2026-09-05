<?php

namespace Tests\Feature;

use App\Models\AiExtraction;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqProposal;
use App\Models\User;
use App\Services\MockAiExtractor;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_ai_extraction_accepts_the_required_text_input(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 25,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ])
            ->assertCreated()
            ->assertJsonPath('extraction.commodity', 'Sugar')
            ->assertJsonPath('extraction.specification', 'ICUMSA 45')
            ->assertJsonPath('extraction.quantity', 25000)
            ->assertJsonPath('extraction.unit', 'MT')
            ->assertJsonPath('extraction.incoterm', 'CIF')
            ->assertJsonPath('extraction.destination', 'Jeddah')
            ->assertJsonPath('extraction.confidence', 0.9)
            ->assertJsonPath('extraction.source', MockAiExtractor::SOURCE)
            ->assertJsonPath('extraction.status', AiExtraction::STATUS_PENDING)
            ->assertJsonPath('extraction.rfq_id', $rfq->id);
    }

    public function test_extraction_is_stored_separately_from_the_official_rfq(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 25,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('ai_extractions', 1);
        $this->assertDatabaseHas('ai_extractions', [
            'rfq_id' => $rfq->id,
            'commodity' => 'Sugar',
            'quantity' => 25000,
            'source' => MockAiExtractor::SOURCE,
        ]);
    }

    public function test_creating_an_ai_extraction_does_not_modify_the_official_rfq(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company, ['quantity' => 25000]);
        $original = $rfq->only(['commodity', 'specification', 'quantity', 'unit', 'incoterm', 'destination', 'status', 'company_id']);

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
                'quantity' => 99999,
                'company_id' => $this->userB()->company_id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('rfq.quantity', 25000)
            ->assertJsonPath('extraction.quantity', 50000);

        $this->assertSame($original, $rfq->fresh()->only(array_keys($original)));
    }

    public function test_quantity_conflict_creates_a_pending_proposal_without_updating_the_official_rfq(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company, ['quantity' => 25000]);

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('rfq.quantity', 25000)
            ->assertJsonPath('extraction.quantity', 50000)
            ->assertJsonPath('extraction.proposals.0.field', 'quantity')
            ->assertJsonPath('extraction.proposals.0.current_value', '25000')
            ->assertJsonPath('extraction.proposals.0.proposed_value', '50000')
            ->assertJsonPath('extraction.proposals.0.source', MockAiExtractor::SOURCE)
            ->assertJsonPath('extraction.proposals.0.status', RfqProposal::STATUS_PENDING);

        $this->assertSame(25000, $rfq->fresh()->quantity);
        $this->assertDatabaseHas('rfq_proposals', [
            'rfq_id' => $rfq->id,
            'field' => 'quantity',
            'current_value' => '25000',
            'proposed_value' => '50000',
            'status' => RfqProposal::STATUS_PENDING,
        ]);
    }

    public function test_authorized_user_can_view_stored_extraction_and_proposal(): void
    {
        $userA = $this->userA();
        $rfq = $this->createOfficialRfq($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ])
            ->assertCreated();

        $this->actingAs($userA, 'sanctum')
            ->getJson('/api/rfqs/'.$rfq->id.'/extractions')
            ->assertOk()
            ->assertJsonPath('extractions.0.quantity', 50000)
            ->assertJsonPath('extractions.0.proposals.0.proposed_value', '50000')
            ->assertJsonPath('extractions.0.proposals.0.status', RfqProposal::STATUS_PENDING);
    }

    public function test_user_a_cannot_create_or_view_extraction_for_company_b_rfq(): void
    {
        $rfqB = $this->createOfficialRfq($this->userB()->company);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqB->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
            ])
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqB->id.'/extractions')
            ->assertNotFound();

        $this->assertDatabaseCount('ai_extractions', 0);
    }

    public function test_client_company_id_cannot_attach_an_extraction_to_another_company_rfq(): void
    {
        $userA = $this->userA();
        $rfqA = $this->createOfficialRfq($userA->company);
        $rfqB = $this->createOfficialRfq($this->userB()->company);

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqA->id.'/extractions', [
                'text' => 'Need 50,000 MT ICUMSA 45 Sugar, CIF Jeddah.',
                'company_id' => $this->userB()->company_id,
                'rfq_id' => $rfqB->id,
            ])
            ->assertCreated()
            ->assertJsonPath('extraction.rfq_id', $rfqA->id);

        $this->assertDatabaseHas('ai_extractions', [
            'rfq_id' => $rfqA->id,
        ]);
        $this->assertDatabaseMissing('ai_extractions', [
            'rfq_id' => $rfqB->id,
        ]);
    }

    private function userA(): User
    {
        return User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
    }

    private function userB(): User
    {
        return User::query()->where('email', UserSeeder::USER_B_EMAIL)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOfficialRfq(Company $company, array $overrides = []): Rfq
    {
        return $company->rfqs()->create([
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 25000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            'status' => Rfq::STATUS_DRAFT,
            ...$overrides,
        ]);
    }
}
