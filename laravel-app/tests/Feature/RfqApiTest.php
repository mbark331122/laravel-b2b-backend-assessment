<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfqApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_a_can_create_an_rfq_for_company_a(): void
    {
        $userA = $this->userA();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload());

        $response->assertCreated()
            ->assertJsonPath('rfq.commodity', 'Sugar')
            ->assertJsonPath('rfq.specification', 'ICUMSA 45')
            ->assertJsonPath('rfq.quantity', 25000)
            ->assertJsonPath('rfq.unit', 'MT')
            ->assertJsonPath('rfq.incoterm', 'CIF')
            ->assertJsonPath('rfq.destination', 'Jeddah')
            ->assertJsonPath('rfq.status', Rfq::STATUS_DRAFT)
            ->assertJsonPath('rfq.company_id', $userA->company_id);

        $this->assertDatabaseHas('rfqs', [
            'id' => $response->json('rfq.id'),
            'company_id' => $userA->company_id,
            'commodity' => 'Sugar',
        ]);
    }

    public function test_user_b_can_create_an_rfq_for_company_b(): void
    {
        $userB = $this->userB();

        $this->actingAs($userB, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'destination' => 'Jeddah',
            ]))
            ->assertCreated()
            ->assertJsonPath('rfq.company_id', $userB->company_id);

        $this->assertDatabaseHas('rfqs', [
            'company_id' => $userB->company_id,
            'commodity' => 'Sugar',
        ]);
    }

    public function test_user_a_can_list_company_a_rfqs(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();

        $companyARfq = $this->createRfq($userA->company);
        $this->createRfq($userB->company, ['destination' => 'Dammam']);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/rfqs');

        $response->assertOk();
        $this->assertCount(1, $response->json('rfqs'));
        $this->assertSame($companyARfq->id, $response->json('rfqs.0.id'));
        $this->assertSame($userA->company_id, $response->json('rfqs.0.company_id'));
    }

    public function test_user_b_can_list_company_b_rfqs(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();

        $this->createRfq($userA->company);
        $companyBRfq = $this->createRfq($userB->company, ['destination' => 'Dammam']);

        $response = $this->actingAs($userB, 'sanctum')->getJson('/api/rfqs');

        $response->assertOk();
        $this->assertCount(1, $response->json('rfqs'));
        $this->assertSame($companyBRfq->id, $response->json('rfqs.0.id'));
        $this->assertSame($userB->company_id, $response->json('rfqs.0.company_id'));
    }

    public function test_admin_can_access_rfqs_across_companies(): void
    {
        $userA = $this->userA();
        $userB = $this->userB();
        $admin = $this->admin();

        $rfqA = $this->createRfq($userA->company);
        $rfqB = $this->createRfq($userB->company, ['destination' => 'Dammam']);

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/rfqs');
        $list->assertOk();
        $this->assertCount(2, $list->json('rfqs'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqA->id)
            ->assertOk()
            ->assertJsonPath('rfq.id', $rfqA->id)
            ->assertJsonPath('rfq.company_id', $userA->company_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqB->id)
            ->assertOk()
            ->assertJsonPath('rfq.id', $rfqB->id)
            ->assertJsonPath('rfq.company_id', $userB->company_id);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/rfqs/'.$rfqA->id, $this->rfqPayload([
                'specification' => 'ICUMSA 45 updated',
            ]))
            ->assertOk()
            ->assertJsonPath('rfq.specification', 'ICUMSA 45 updated')
            ->assertJsonPath('rfq.company_id', $userA->company_id);
    }

    public function test_user_without_rfq_permissions_cannot_perform_rfq_operations(): void
    {
        $userA = $this->userA();
        $rfq = $this->createRfq($userA->company);
        $userWithoutPermission = $this->companyUserWithoutRfqPermissions($userA->company);

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload())
            ->assertForbidden();

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->getJson('/api/rfqs')
            ->assertForbidden();

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->getJson('/api/rfqs/'.$rfq->id)
            ->assertForbidden();

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->putJson('/api/rfqs/'.$rfq->id, $this->rfqPayload())
            ->assertForbidden();
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

    private function companyUserWithoutRfqPermissions(Company $company): User
    {
        $role = Role::query()->create(['name' => 'no_rfq']);
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('name', [Permission::BANK_READ])
                ->pluck('id')
        );

        $user = new User;
        $user->name = 'No RFQ User';
        $user->email = 'no.rfq@example.com';
        $user->password = UserSeeder::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
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
