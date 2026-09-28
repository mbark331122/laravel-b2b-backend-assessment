<?php

namespace Tests\Feature\Security;

use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ReturnShipment;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;

class ReturnShipmentSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$rmaId, $returnShipmentId, $rmaItemId] = $this->pendingReturnShipmentContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/return-shipments/'.$returnShipmentId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/return-shipments/'.$returnShipmentId.'/ship')
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/return-shipments/'.$returnShipmentId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/return-shipments/'.$returnShipmentId.'/ship')
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/return-shipments/'.$returnShipmentId.'/cancel')
            ->assertForbidden();
    }

    public function test_spoofing_immutability_and_permissions(): void
    {
        [$rmaId, $returnShipmentId, $rmaItemId] = $this->pendingReturnShipmentContext();

        $original = ReturnShipment::query()->findOrFail($returnShipmentId);

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/return-shipments/'.$returnShipmentId, [
                'status' => ReturnShipment::STATUS_DELIVERED,
            ])
            ->assertStatus(405);

        $this->actingAs($this->userA(), 'sanctum')
            ->deleteJson('/api/return-shipments/'.$returnShipmentId)
            ->assertStatus(405);

        $fresh = ReturnShipment::query()->findOrFail($returnShipmentId);
        $this->assertSame($original->number, $fresh->number);
        $this->assertSame(ReturnShipment::STATUS_PENDING, $fresh->status);

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.ret.buyer@example.com',
            [Permission::RMA_READ, Permission::SHIPMENT_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/return-shipments')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function pendingReturnShipmentContext(string $title = 'Sec Ret'): array
    {
        [$rmaId, $rmaItemId] = $this->approvedRmaOnly($title);

        $returnShipmentId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('return_shipment.id');

        return [$rmaId, $returnShipmentId, $rmaItemId];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function approvedRmaOnly(string $title = 'Sec Ret'): array
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
                'quantity' => 500,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 500],
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
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(7)->toDateString(),
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 12,
                ]],
            ])
            ->json('quotation.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->json('negotiation.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 11,
                ]],
            ])
            ->assertCreated();

        $offerId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 11,
                ]],
            ])
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
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')->assertOk();

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

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk();

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        return [$rmaId, $rmaItemId];
    }
}
