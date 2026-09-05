<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfqTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_a_cannot_view_company_b_rfq(): void
    {
        $rfqB = $this->createRfq($this->userB()->company, ['destination' => 'Dammam']);

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqB->id)
            ->assertNotFound()
            ->assertJsonMissing(['rfq']);
    }

    public function test_user_b_cannot_view_company_a_rfq(): void
    {
        $rfqA = $this->createRfq($this->userA()->company);

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqA->id)
            ->assertNotFound()
            ->assertJsonMissing(['rfq']);
    }

    public function test_user_a_cannot_update_company_b_rfq(): void
    {
        $rfqB = $this->createRfq($this->userB()->company, ['destination' => 'Dammam']);

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/rfqs/'.$rfqB->id, $this->rfqPayload([
                'destination' => 'Hijacked',
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqB->id,
            'company_id' => $this->userB()->company_id,
            'destination' => 'Dammam',
        ]);
    }

    public function test_user_b_cannot_update_company_a_rfq(): void
    {
        $rfqA = $this->createRfq($this->userA()->company);

        $this->actingAs($this->userB(), 'sanctum')
            ->putJson('/api/rfqs/'.$rfqA->id, $this->rfqPayload([
                'destination' => 'Hijacked',
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqA->id,
            'company_id' => $this->userA()->company_id,
            'destination' => 'Jeddah',
        ]);
    }

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

    public function test_user_b_cannot_create_an_rfq_under_company_a_by_sending_company_id(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();

        $response = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $userA->company_id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('rfq.company_id', $userB->company_id);

        $this->assertDatabaseHas('rfqs', [
            'id' => $response->json('rfq.id'),
            'company_id' => $userB->company_id,
        ]);

        $this->assertDatabaseMissing('rfqs', [
            'company_id' => $userA->company_id,
        ]);
    }

    public function test_user_a_list_ignores_company_id_query_parameter(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();

        $this->createRfq($userA->company);
        $this->createRfq($userB->company, ['destination' => 'Dammam']);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/rfqs?company_id='.$userB->company_id);

        $response->assertOk();
        $this->assertCount(1, $response->json('rfqs'));
        $this->assertSame($userA->company_id, $response->json('rfqs.0.company_id'));
    }

    public function test_user_a_cannot_move_own_rfq_to_company_b_on_update(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();
        $rfqA = $this->createRfq($userA->company);

        $this->actingAs($userA, 'sanctum')
            ->putJson('/api/rfqs/'.$rfqA->id, $this->rfqPayload([
                'company_id' => $userB->company_id,
                'destination' => 'Yanbu',
            ]))
            ->assertOk()
            ->assertJsonPath('rfq.company_id', $userA->company_id)
            ->assertJsonPath('rfq.destination', 'Yanbu');

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqA->id,
            'company_id' => $userA->company_id,
            'destination' => 'Yanbu',
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
    private function createRfq(Company $company, array $overrides = []): Rfq
    {
        return $company->rfqs()->create([
            ...$this->rfqPayload($overrides),
            'status' => Rfq::STATUS_DRAFT,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rfqPayload(array $overrides = []): array
    {
        return [
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 25000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            ...$overrides,
        ];
    }
}
