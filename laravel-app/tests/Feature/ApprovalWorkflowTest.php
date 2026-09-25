<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Tests\Feature\Security\SecurityTestCase;

class ApprovalWorkflowTest extends SecurityTestCase
{
    public function test_company_can_manage_approval_policy(): void
    {
        $user = $this->userA();

        $create = $this->actingAs($user, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'RFQ Submit Gate',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'priority' => 10,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
                'company_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('approval_policy.company_id', $user->company_id)
            ->assertJsonPath('approval_policy.approval_type', ApprovalPolicy::TYPE_RFQ_SUBMIT)
            ->assertJsonCount(1, 'approval_policy.steps');

        $id = $create->json('approval_policy.id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/approval-policies/'.$id, [
                'name' => 'RFQ Submit Gate v2',
                'priority' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('approval_policy.name', 'RFQ Submit Gate v2');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/approval-policies/'.$id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('approval_policy.is_active', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/approval-policies/'.$id.'/activate')
            ->assertOk()
            ->assertJsonPath('approval_policy.is_active', true);
    }

    public function test_rfq_submit_is_gated_until_approved(): void
    {
        $requester = $this->userA();
        $approver = $this->makeCompanyUser($requester->company, 'approver.a@example.com');

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'RFQ Gate',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $rfqId = $this->createRfq($requester);

        $submit = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_PENDING);

        $this->assertSame(Rfq::STATUS_DRAFT, Rfq::query()->findOrFail($rfqId)->status);

        $requestId = $submit->json('approval_request.id');

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertStatus(422);

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk()
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_APPROVED);

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_SUBMITTED);
    }

    public function test_multi_step_ordering_and_rejection(): void
    {
        $requester = $this->userA();
        $step1 = $this->makeCompanyUser($requester->company, 'step1.a@example.com');
        $step2 = $this->makeCompanyUser($requester->company, 'step2.a@example.com');

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Two Step',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                    ['step_order' => 2, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $this->actingAs($step1, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk()
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_PENDING)
            ->assertJsonPath('approval_request.current_step_order', 2);

        // Earlier step cannot be re-decided; current step is 2.
        $decisions = $this->actingAs($step1, 'sanctum')
            ->getJson('/api/approval-requests/'.$requestId)
            ->assertOk()
            ->json('approval_request.decisions');
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $decisions[0]['status']);
        $this->assertSame('pending', $decisions[1]['status']);

        $this->actingAs($step2, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/reject', [
                'reason' => 'Needs revision',
            ])
            ->assertOk()
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_REJECTED);

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422);

        $this->assertSame(Rfq::STATUS_DRAFT, Rfq::query()->findOrFail($rfqId)->status);
    }

    public function test_cancel_pending_request_by_requester(): void
    {
        $requester = $this->userA();
        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Cancelable',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/cancel')
            ->assertOk()
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_CANCELLED);

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertStatus(422);
    }

    public function test_po_submit_amount_threshold_uses_server_total(): void
    {
        $buyer = $this->userA();
        $approver = $this->makeCompanyUser($buyer->company, 'po.approver@example.com');

        // Threshold above typical quote total (~1125) — no gate.
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Very High PO',
                'approval_type' => ApprovalPolicy::TYPE_PURCHASE_ORDER_SUBMIT,
                'min_amount' => '999999.00',
                'currency' => 'USD',
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $lowPoId = $this->createDraftPurchaseOrderId();
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$lowPoId.'/submit')
            ->assertOk();

        // Lower threshold — gate applies; client amount spoof ignored.
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Any PO',
                'approval_type' => ApprovalPolicy::TYPE_PURCHASE_ORDER_SUBMIT,
                'min_amount' => '1.00',
                'currency' => 'USD',
                'priority' => 100,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $highPoId = $this->createDraftPurchaseOrderId('High Gate PO');
        $blocked = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$highPoId.'/submit', [
                'amount' => '1.00',
            ])
            ->assertStatus(422);

        $serverAmount = $blocked->json('approval_request.target_context.amount');
        $this->assertNotSame('1.00', $serverAmount);
        $this->assertTrue((float) $serverAmount >= 1.0);

        $requestId = $blocked->json('approval_request.id');
        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$highPoId.'/submit')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_PENDING_SUPPLIER_CONFIRMATION);
    }

    public function test_policy_update_does_not_rewrite_pending_request_steps(): void
    {
        $requester = $this->userA();
        $approver = $this->makeCompanyUser($requester->company, 'snap.approver@example.com');

        $policyId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'Snap',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated()
            ->json('approval_policy.id');

        $rfqId = $this->createRfq($requester);
        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422)
            ->json('approval_request.id');

        $this->actingAs($requester, 'sanctum')
            ->putJson('/api/approval-policies/'.$policyId, [
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                    ['step_order' => 2, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertOk();

        $show = $this->actingAs($requester, 'sanctum')
            ->getJson('/api/approval-requests/'.$requestId)
            ->assertOk();

        $this->assertCount(1, $show->json('approval_request.policy_steps'));
        $this->assertCount(1, $show->json('approval_request.decisions'));

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk()
            ->assertJsonPath('approval_request.status', ApprovalRequest::STATUS_APPROVED);
    }

    private function makeCompanyUser($company, string $email): User
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

    private function createDraftPurchaseOrderId(string $title = 'PO Approval RFQ'): int
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => $title,
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1000],
                ],
            ])
            ->assertCreated()
            ->json('rfq.id');

        Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();

        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertCreated()
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(10)->toDateString(),
                'shipping_amount' => 50,
                'tax_amount' => 25,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 100,
                    'unit_price' => 10.5,
                ]],
            ])
            ->assertCreated()
            ->json('quotation.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        $offerId = (int) \App\Models\NegotiationOffer::query()
            ->where('negotiation_id', $negotiationId)
            ->orderBy('sequence')
            ->value('id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$offerId.'/accept')
            ->assertOk();

        return $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertSuccessful()
            ->json('purchase_order.id');
    }
}
