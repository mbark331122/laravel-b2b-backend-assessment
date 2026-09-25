<?php

namespace Tests\Feature;

use App\Models\ApprovalPolicy;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Tests\Feature\Security\SecurityTestCase;

class NotificationFoundationTest extends SecurityTestCase
{
    public function test_rfq_submit_notifies_buyer_company_users_once(): void
    {
        $buyer = $this->userA();
        $peer = $this->makeCompanyUser($buyer->company, 'peer.notify@a.example.com');

        $rfqId = $this->actingAs($buyer, 'sanctum')
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

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $buyer->id)
            ->where('type', Notification::TYPE_RFQ_SUBMITTED)
            ->count());

        $this->assertSame(1, Notification::query()
            ->where('user_id', $peer->id)
            ->where('type', Notification::TYPE_RFQ_SUBMITTED)
            ->count());

        // Idempotent: replaying creation path cannot duplicate (already submitted).
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_rfq_distribution_notifies_supplier_only(): void
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();
        $product = \App\Models\Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = \App\Models\SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
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

        \App\Models\Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();

        Notification::query()->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertCreated();

        $this->assertTrue(Notification::query()
            ->where('user_id', $supplier->id)
            ->where('type', Notification::TYPE_RFQ_DISTRIBUTED)
            ->exists());

        $this->assertFalse(Notification::query()
            ->where('user_id', $buyer->id)
            ->where('type', Notification::TYPE_RFQ_DISTRIBUTED)
            ->exists());
    }

    public function test_inbox_is_recipient_scoped_and_mark_read_works(): void
    {
        $buyer = $this->userA();
        $peer = $this->makeCompanyUser($buyer->company, 'inbox.peer@a.example.com');

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
            ])
            ->json('rfq.id');
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();

        $mine = Notification::query()->where('user_id', $buyer->id)->firstOrFail();
        $peerNote = Notification::query()->where('user_id', $peer->id)->firstOrFail();

        $list = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk();

        $ids = collect($list->json('notifications'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($peerNote->id, $ids);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/notifications/'.$mine->id.'/read')
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/notifications/'.$mine->id.'/read')
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/notifications/read-all')
            ->assertOk();
    }

    public function test_approval_request_notifies_approvers_and_requester(): void
    {
        $requester = $this->userA();
        $approver = $this->makeCompanyUser($requester->company, 'appr.notify@a.example.com');

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/approval-policies', [
                'name' => 'RFQ Gate',
                'approval_type' => ApprovalPolicy::TYPE_RFQ_SUBMIT,
                'steps' => [
                    ['step_order' => 1, 'approver_role' => Role::COMPANY_USER],
                ],
            ])
            ->assertCreated();

        $rfqId = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
            ])
            ->json('rfq.id');

        $blocked = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertStatus(422);

        $requestId = $blocked->json('approval_request.id');

        $this->assertTrue(Notification::query()
            ->where('user_id', $approver->id)
            ->where('type', Notification::TYPE_APPROVAL_STEP_REQUIRED)
            ->exists());

        $this->assertTrue(Notification::query()
            ->where('user_id', $requester->id)
            ->where('type', Notification::TYPE_APPROVAL_REQUEST_CREATED)
            ->exists());

        // Requester must not get step-required (self-approval exclusion).
        $this->assertFalse(Notification::query()
            ->where('user_id', $requester->id)
            ->where('type', Notification::TYPE_APPROVAL_STEP_REQUIRED)
            ->exists());

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/approval-requests/'.$requestId.'/approve')
            ->assertOk();

        $this->assertTrue(Notification::query()
            ->where('user_id', $requester->id)
            ->where('type', Notification::TYPE_APPROVAL_REQUEST_APPROVED)
            ->exists());
    }

    public function test_purchase_order_submit_notification_omits_party_identities(): void
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();

        // Build a draft PO via negotiation helper path used in Sprint 19 tests.
        $poId = $this->createDraftPurchaseOrderId();

        Notification::query()->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $note = Notification::query()
            ->where('user_id', $supplier->id)
            ->where('type', Notification::TYPE_PURCHASE_ORDER_SUBMITTED)
            ->firstOrFail();

        $payload = $note->toApiArray();
        $json = json_encode($payload);
        $this->assertStringNotContainsString('Company A', $json);
        $this->assertStringNotContainsString('Supplier Company', $json);
        $this->assertArrayNotHasKey('buyer_company_id', $payload['metadata']);
        $this->assertArrayNotHasKey('supplier_company_id', $payload['metadata']);
        $this->assertSame($supplier->company_id, $note->company_id);
    }

    public function test_failed_rfq_submit_does_not_notify(): void
    {
        $buyer = $this->userA();
        $rfq = $this->createOfficialRfq($buyer->company);
        // No items — submit fails.
        Notification::query()->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/submit')
            ->assertStatus(422);

        $this->assertSame(0, Notification::query()->count());
    }

    private function makeCompanyUser($company, string $email): User
    {
        $role = Role::query()->where('name', Role::COMPANY_USER)->firstOrFail();
        $user = new User;
        $user->name = 'Peer';
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->is_active = true;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }

    private function createDraftPurchaseOrderId(): int
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();
        $product = \App\Models\Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = \App\Models\SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Notify PO',
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

        \App\Models\Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();

        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertCreated()
            ->json('distributions.0.id');

        $rfqItemId = (int) \App\Models\Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

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
