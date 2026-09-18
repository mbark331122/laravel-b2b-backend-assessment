<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Negotiation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_creates_po_from_accepted_negotiation_with_snapshot_and_number(): void
    {
        [$buyer, $supplier, $negotiationId, $acceptedOfferId] = $this->acceptedNegotiationContext();

        $po = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order', [
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'tenant_id' => 999,
                'number' => 'CLIENT-PO',
                'status' => PurchaseOrder::STATUS_CONFIRMED,
                'subtotal' => 1,
                'total' => 1,
                'accepted_offer_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_DRAFT)
            ->assertJsonPath('purchase_order.negotiation_id', $negotiationId)
            ->assertJsonPath('purchase_order.accepted_offer_id', $acceptedOfferId)
            ->assertJsonPath('purchase_order.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('purchase_order.supplier_company.id', $supplier->company_id)
            ->json('purchase_order');

        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{6}$/', $po['number']);
        $this->assertNotSame('CLIENT-PO', $po['number']);
        $this->assertSame('735.00', $po['total']);
        $this->assertCount(1, $po['items']);
        $this->assertSame('720.00', $po['items'][0]['line_total']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PURCHASE_ORDER_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertOk()
            ->assertJsonPath('purchase_order.id', $po['id'])
            ->assertJsonPath('purchase_order.number', $po['number']);

        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_full_lifecycle_submit_confirm_complete_and_snapshot_integrity(): void
    {
        [$buyer, $supplier, $negotiationId] = $this->acceptedNegotiationContext();

        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertCreated()
            ->json('purchase_order.id');

        $before = PurchaseOrder::query()->with('items')->findOrFail($poId);
        $snapshotTotal = (string) $before->total;
        $snapshotQty = $before->items->first()->quantity;
        $snapshotPrice = (string) $before->items->first()->unit_price;
        $snapshotDesc = $before->items->first()->description;

        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $product->update(['name' => 'Changed After PO', 'wholesale_price' => '1.00']);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/purchase-orders/'.$poId)
            ->assertNotFound();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_PENDING_SUPPLIER_CONFIRMATION);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/purchase-orders')
            ->assertOk()
            ->assertJsonCount(1, 'purchase_orders');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CONFIRMED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/complete')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_COMPLETED);

        $after = PurchaseOrder::query()->with('items')->findOrFail($poId);
        $this->assertSame($snapshotTotal, (string) $after->total);
        $this->assertSame($snapshotQty, $after->items->first()->quantity);
        $this->assertSame($snapshotPrice, (string) $after->items->first()->unit_price);
        $this->assertSame($snapshotDesc, $after->items->first()->description);
        $this->assertNotSame('Changed After PO', $after->items->first()->description);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/cancel')
            ->assertUnprocessable();
    }

    public function test_supplier_reject_and_buyer_cancel_rules(): void
    {
        [$buyer, $supplier, $negotiationId] = $this->acceptedNegotiationContext();

        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->json('purchase_order.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/reject', [])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/reject', [
                'reason' => 'Cannot fulfill quantity',
            ])
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_REJECTED)
            ->assertJsonPath('purchase_order.rejection_reason', 'Cannot fulfill quantity');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertUnprocessable();

        [$buyer2, $supplier2, $negotiationId2] = $this->acceptedNegotiationContext(title: 'Cancel RFQ');
        $poCancel = $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId2.'/purchase-order')
            ->json('purchase_order.id');

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poCancel.'/cancel')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CANCELLED);
    }

    public function test_non_accepted_negotiation_cannot_create_po(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId] = $this->openOnlyNegotiationContext();

        $negotiationId = Negotiation::query()->where('quotation_id', $quotationId)->value('id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertUnprocessable();
    }

    public function test_invalid_lifecycle_transitions_blocked(): void
    {
        [$buyer, $supplier, $negotiationId] = $this->acceptedNegotiationContext();

        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->json('purchase_order.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertNotFound();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/complete')
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function acceptedNegotiationContext(string $title = 'PO RFQ'): array
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext($title);

        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 8,
                    'unit_price' => 90,
                ]],
            ])
            ->assertCreated();

        $acceptedOfferId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 8,
                    'unit_price' => 90,
                ]],
            ])
            ->assertCreated()
            ->json('offer.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$acceptedOfferId.'/accept')
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_ACCEPTED);

        return [$buyer, $supplier, $negotiationId, $acceptedOfferId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function openOnlyNegotiationContext(): array
    {
        [$buyer, $supplier, $rfqId, $quotationId] = $this->submittedQuoteContext('Open Only');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated();

        return [$buyer, $supplier, $rfqId, $quotationId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function submittedQuoteContext(string $title = 'PO RFQ'): array
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
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

        return [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId];
    }
}
