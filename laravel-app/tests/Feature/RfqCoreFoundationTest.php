<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfqCoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_creates_draft_rfq_with_server_side_ownership_and_legacy_item(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $otherCompanyId = User::query()->where('email', UserSeeder::USER_B_EMAIL)->value('company_id');

        $response = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Sugar RFQ',
                'description' => 'Need refined sugar',
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 25000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
                'company_id' => $otherCompanyId,
                'buyer_company_id' => $otherCompanyId,
                'tenant_id' => $otherCompanyId,
                'user_id' => 999,
                'owner_id' => 999,
                'status' => Rfq::STATUS_SUBMITTED,
            ])
            ->assertCreated()
            ->assertJsonPath('rfq.company_id', $buyer->company_id)
            ->assertJsonPath('rfq.status', Rfq::STATUS_DRAFT)
            ->assertJsonPath('rfq.title', 'Sugar RFQ')
            ->assertJsonCount(1, 'rfq.items');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RFQ_CREATED,
            'company_id' => $buyer->company_id,
            'actor_id' => $buyer->id,
        ]);
    }

    public function test_rfq_items_crud_and_product_snapshot_while_draft(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $product = Product::query()->where('status', Product::STATUS_PUBLISHED)->firstOrFail();
        $originalName = $product->name;

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
            ])
            ->assertCreated()
            ->json('rfq.id');

        $itemId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/items', [
                'product_id' => $product->id,
                'quantity' => 2000,
                'target_price' => 400,
                'company_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('rfq_item.product_id', $product->id)
            ->assertJsonPath('rfq_item.item_name', $originalName)
            ->assertJsonPath('rfq_item.product_snapshot.name', $originalName)
            ->json('rfq_item.id');

        $product->update(['name' => 'Changed After Snapshot']);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId)
            ->assertOk()
            ->assertJsonPath('rfq.items.1.product_snapshot.name', $originalName)
            ->assertJsonPath('rfq.items.1.item_name', $originalName);

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/rfqs/'.$rfqId.'/items/'.$itemId, [
                'quantity' => 3000,
            ])
            ->assertOk()
            ->assertJsonPath('rfq_item.quantity', 3000);

        $this->actingAs($buyer, 'sanctum')
            ->deleteJson('/api/rfqs/'.$rfqId.'/items/'.$itemId)
            ->assertOk();

        $this->assertDatabaseMissing('rfq_items', ['id' => $itemId]);
    }

    public function test_lifecycle_submit_cancel_close_and_immutability(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
            ])
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit', ['status' => Rfq::STATUS_CLOSED])
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_SUBMITTED);

        $this->actingAs($buyer, 'sanctum')
            ->putJson('/api/rfqs/'.$rfqId, [
                'commodity' => 'Hijack',
                'specification' => 'Nope',
                'quantity' => 1,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Nowhere',
                'status' => Rfq::STATUS_DRAFT,
            ])
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/items', [
                'item_name' => 'Extra',
                'quantity' => 10,
                'unit' => 'MT',
            ])
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->deleteJson('/api/rfqs/'.$rfqId)
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/close')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_CLOSED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertUnprocessable();

        $draftId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Wheat',
                'specification' => 'Grade A',
                'quantity' => 500,
                'unit' => 'MT',
                'incoterm' => 'FOB',
                'destination' => 'Dammam',
            ])
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$draftId.'/cancel')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_CANCELLED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$draftId.'/close')
            ->assertUnprocessable();
    }

    public function test_submit_requires_items_and_rejects_non_published_products(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $draftProduct = Product::query()->where('status', Product::STATUS_DRAFT)->firstOrFail();
        $archived = Product::query()->where('status', Product::STATUS_ARCHIVED)->firstOrFail();

        $rfq = new Rfq([
            'title' => 'Empty',
            'commodity' => 'X',
            'specification' => 'Y',
            'quantity' => 1,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
        ]);
        $rfq->company()->associate($buyer->company);
        $rfq->status = Rfq::STATUS_DRAFT;
        $rfq->save();
        // Ensure no items
        $rfq->items()->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfq->id.'/submit')
            ->assertUnprocessable();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
            ])
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/items', [
                'product_id' => $draftProduct->id,
                'quantity' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/items', [
                'product_id' => $archived->id,
                'quantity' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_cross_company_and_supplier_isolation(): void
    {
        $buyerA = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $buyerB = User::query()->where('email', UserSeeder::USER_B_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        $rfqId = $this->actingAs($buyerA, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
            ])
            ->json('rfq.id');

        $itemId = $this->actingAs($buyerA, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId)
            ->json('rfq.items.0.id');

        $this->actingAs($buyerB, 'sanctum')->getJson('/api/rfqs/'.$rfqId)->assertNotFound();
        $this->actingAs($buyerB, 'sanctum')->putJson('/api/rfqs/'.$rfqId, [
            'commodity' => 'Hack',
            'specification' => 'Hack',
            'quantity' => 1,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Hack',
        ])->assertNotFound();
        $this->actingAs($buyerB, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertNotFound();
        $this->actingAs($buyerB, 'sanctum')->deleteJson('/api/rfqs/'.$rfqId.'/items/'.$itemId)->assertNotFound();
        $this->actingAs($buyerB, 'sanctum')->deleteJson('/api/rfqs/'.$rfqId)->assertNotFound();

        $this->actingAs($supplier, 'sanctum')->getJson('/api/rfqs')->assertForbidden();
        $this->actingAs($supplier, 'sanctum')->getJson('/api/rfqs/'.$rfqId)->assertForbidden();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertForbidden();
    }

    public function test_rfq_list_filters_by_status(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $draftId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'A',
                'quantity' => 1,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
            ])
            ->json('rfq.id');

        $submittedId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Wheat',
                'specification' => 'B',
                'quantity' => 2,
                'unit' => 'MT',
                'incoterm' => 'FOB',
                'destination' => 'Dammam',
            ])
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$submittedId.'/submit')->assertOk();

        $drafts = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs?status=draft')
            ->assertOk()
            ->json('rfqs');

        $this->assertTrue(collect($drafts)->pluck('id')->contains($draftId));
        $this->assertFalse(collect($drafts)->pluck('id')->contains($submittedId));
    }
}
