<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Rfq;

class RfqCoreSecurityTest extends SecurityTestCase
{
    public function test_ownership_spoofing_fields_cannot_override_rfq_company(): void
    {
        $response = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $this->userB()->company_id,
                'buyer_company_id' => $this->userB()->company_id,
                'tenant_id' => $this->userB()->company_id,
                'user_id' => $this->userB()->id,
                'owner_id' => $this->userB()->id,
                'status' => Rfq::STATUS_CLOSED,
            ]))
            ->assertCreated();

        $this->assertSame($this->userA()->company_id, $response->json('rfq.company_id'));
        $this->assertSame(Rfq::STATUS_DRAFT, $response->json('rfq.status'));
    }

    public function test_missing_rfq_lifecycle_permissions_are_denied(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        app(\App\Services\RfqService::class)->ensureLegacyItem($rfq);

        $user = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.rfq.lifecycle@example.com',
            [Permission::RFQ_READ, Permission::RFQ_CREATE, Permission::RFQ_UPDATE]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/submit')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/cancel')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/submit')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/close')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/rfqs/'.$rfq->id)
            ->assertForbidden();
    }

    public function test_product_visibility_gate_for_rfq_items(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        $draft = Product::query()->where('status', Product::STATUS_DRAFT)->firstOrFail();
        $archived = Product::query()->where('status', Product::STATUS_ARCHIVED)->firstOrFail();
        $published = Product::query()->where('status', Product::STATUS_PUBLISHED)->firstOrFail();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/items', [
                'product_id' => $draft->id,
                'quantity' => 5,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/items', [
                'product_id' => $archived->id,
                'quantity' => 5,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/items', [
                'product_id' => $published->id,
                'quantity' => 5,
            ])
            ->assertCreated();
    }
}
