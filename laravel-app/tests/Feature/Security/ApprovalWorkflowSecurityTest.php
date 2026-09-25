<?php

namespace Tests\Feature\Security;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;

class ApprovalWorkflowSecurityTest extends SecurityTestCase
{
    public function test_cross_company_policy_isolation(): void
    {
        $policyA = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'A Only',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated()
            ->json('approval_policy.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/approval-policies/'.$policyA)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->putJson('/api/approval-policies/'.$policyA, [
                'name' => 'Hijack',
            ])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/approval-policies/'.$policyA.'/deactivate')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/approval-policies')
            ->assertOk()
            ->assertJsonMissing(['name' => 'A Only']);
    }

    public function test_cross_company_request_isolation(): void
    {
        $requester = $this->userA();
        $this->seedRfqPolicy($requester);

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/approval-requests/'.$requestId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/reject', [
                'reason' => 'Nope',
            ])
            ->assertNotFound();
    }

    public function test_spoofed_fields_are_ignored_on_policy_create(): void
    {
        $user = $this->userA();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Owned',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'company_id' => $this->userB()->company_id,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('approval_policy.company_id', $user->company_id);
    }

    public function test_unauthorized_without_permission_gets_403(): void
    {
        $limited = $this->userWithoutPermissions($this->userA()->company, 'no.approval@a.example', [
            Permission::RFQ_READ,
            Permission::RFQ_CREATE,
            Permission::RFQ_SUBMIT,
        ]);

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Denied',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertForbidden();
    }

    public function test_wrong_role_cannot_decide_step(): void
    {
        $requester = $this->userA();
        $this->seedRfqPolicy($requester);

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $supplierOnBuyerCompany = $this->userWithoutPermissions(
            $requester->company,
            'supplier.role@a.example',
            Permission::approvalWorkflowNames(),
        );
        $supplierRole = Role::query()->where('name', Role::SUPPLIER_USER)->firstOrFail();
        $supplierOnBuyerCompany->role()->associate($supplierRole);
        $supplierOnBuyerCompany->save();

        $this->actingAs($supplierOnBuyerCompany->fresh(), 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertStatus(422);
    }

    public function test_duplicate_decision_and_terminal_state_protected(): void
    {
        $requester = $this->userA();
        $approver = $this->makeApprover($requester->company, 'dup.decide@a.example');
        $this->seedRfqPolicy($requester);

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertStatus(422);

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/reject', [
                'reason' => 'Too late',
            ])
            ->assertStatus(422);
    }

    private function seedRfqPolicy(User $user): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'RFQ Gate',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();
    }

    private function createRfq(User $buyer): int
    {
        return $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->json('rfq.id');
    }

    private function makeApprover($company, string $email): User
    {
        $role = Role::query()->where('name', Role::COMPANY_USER)->firstOrFail();
        $user = new User;
        $user->name = 'Approver';
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->is_active = true;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }
}
