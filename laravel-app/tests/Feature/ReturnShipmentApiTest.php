<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Negotiation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnShipment;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_creates_return_shipment_for_approved_rma_with_lifecycle(): void
    {
        [$buyer, $supplier, $rmaId, $rmaItemId, $poId, $shipmentId] = $this->approvedRmaContext();

        $returnShipment = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'carrier' => 'Return Carrier',
                'tracking_number' => 'RET-001',
                'shipping_method' => 'courier',
                'items' => [[
                    'rma_item_id' => $rmaItemId,
                    'quantity' => 1,
                ]],
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'rma_id' => 999,
                'number' => 'CLIENT-RET',
                'status' => ReturnShipment::STATUS_SHIPPED,
            ])
            ->assertCreated()
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_PENDING)
            ->assertJsonPath('return_shipment.rma_id', $rmaId)
            ->assertJsonPath('return_shipment.shipment_id', $shipmentId)
            ->assertJsonPath('return_shipment.purchase_order_id', $poId)
            ->assertJsonPath('return_shipment.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('return_shipment.supplier_company.id', $supplier->company_id)
            ->json('return_shipment');

        $this->assertMatchesRegularExpression('/^RMA-RET-\d{4}-\d{6}$/', $returnShipment['number']);
        $this->assertNotSame('CLIENT-RET', $returnShipment['number']);
        $this->assertSame(1, $returnShipment['items'][0]['quantity']);
        $this->assertSame($rmaItemId, $returnShipment['items'][0]['rma_item_id']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RETURN_SHIPMENT_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $id = $returnShipment['id'];

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('return_shipment.id', $id);

        $this->actingAs($buyer, 'sanctum')
            ->patchJson('/api/return-shipments/'.$id, [
                'carrier' => 'Updated Carrier',
                'tracking_number' => 'RET-002',
                'status' => ReturnShipment::STATUS_DELIVERED,
                'number' => 'HACK',
            ])
            ->assertOk()
            ->assertJsonPath('return_shipment.carrier', 'Updated Carrier')
            ->assertJsonPath('return_shipment.tracking_number', 'RET-002')
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_PENDING);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/return-shipments/'.$id.'/ship')
            ->assertOk()
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_SHIPPED);

        $this->actingAs($buyer, 'sanctum')
            ->patchJson('/api/return-shipments/'.$id, ['carrier' => 'Too Late'])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/return-shipments/'.$id.'/deliver')
            ->assertOk()
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_DELIVERED);

        $this->assertSame(Rma::STATUS_APPROVED, Rma::query()->find($rmaId)->status);
        $this->assertSame(Shipment::STATUS_DELIVERED, Shipment::query()->find($shipmentId)->status);
        $this->assertSame(PurchaseOrder::STATUS_CONFIRMED, PurchaseOrder::query()->find($poId)->status);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/return-shipments/'.$id.'/cancel')
            ->assertForbidden();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/return-shipments/'.$id.'/cancel')
            ->assertUnprocessable();
    }

    public function test_eligibility_cancel_and_quantity_rules(): void
    {
        [$buyer, $supplier, $requestedRmaId, $rmaItemId] = $this->requestedRmaContext(title: 'Ret Pending');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$requestedRmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$requestedRmaId.'/approve')
            ->assertOk();

        $rmaQty = (int) RmaItem::query()->find($rmaItemId)->quantity;

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$requestedRmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => $rmaQty + 1]],
            ])
            ->assertUnprocessable();

        $id = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$requestedRmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('return_shipment.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/return-shipments/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_CANCELLED);

        $retry = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$requestedRmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('return_shipment');

        $this->assertNotSame($id, $retry['id']);
    }

    public function test_buyer_and_supplier_list_endpoints(): void
    {
        [$buyer, $supplier, $rmaId, $rmaItemId] = $this->approvedRmaContext(title: 'Ret List');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/return-shipments')
            ->assertOk()
            ->assertJsonCount(1, 'return_shipments');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/return-shipments')
            ->assertOk()
            ->assertJsonCount(1, 'return_shipments');
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4?: int, 5?: int}
     */
    private function approvedRmaContext(string $title = 'Ret RFQ'): array
    {
        [$buyer, $supplier, $rmaId, $rmaItemId, $poId, $shipmentId] = $this->requestedRmaContext($title);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_APPROVED);

        return [$buyer, $supplier, $rmaId, $rmaItemId, $poId, $shipmentId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: int}
     */
    private function requestedRmaContext(string $title = 'Ret RFQ'): array
    {
        [$buyer, $supplier, $shipmentId, $poId, $shipmentItemId] = $this->confirmedDeliveryContext($title);

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        return [$buyer, $supplier, $rmaId, $rmaItemId, $poId, $shipmentId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function confirmedDeliveryContext(string $title = 'Ret RFQ'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext($title);

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->json('shipment.id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/processing')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/deliver')->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated();

        $shipmentItemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        return [$buyer, $supplier, $shipmentId, $poId, $shipmentItemId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'Ret RFQ'): array
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

        $offerId = $this->actingAs($supplier, 'sanctum')
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
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$offerId.'/accept')
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_ACCEPTED);

        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertCreated()
            ->json('purchase_order.id');

        $this->actingAs($buyer, 'sanctum')->postJson('/api/purchase-orders/'.$poId.'/submit')->assertOk();
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CONFIRMED);

        return [$buyer, $supplier, $poId];
    }
}
