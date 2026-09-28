<?php

namespace Tests\Feature\Security;

use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;

class RmaSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$shipmentId, $rmaId, $itemId] = $this->requestedRmaContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rmas/'.$rmaId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/rmas/'.$rmaId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertNotFound();
    }

    public function test_spoofing_immutability_and_permissions(): void
    {
        [$shipmentId, $rmaId, $itemId] = $this->requestedRmaContext();

        $original = Rma::query()->with('items')->findOrFail($rmaId);

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/rmas/'.$rmaId, ['status' => Rma::STATUS_APPROVED])
            ->assertStatus(405);

        $this->actingAs($this->userA(), 'sanctum')
            ->patchJson('/api/rmas/'.$rmaId, ['buyer_company_id' => 1])
            ->assertStatus(405);

        $this->actingAs($this->userA(), 'sanctum')
            ->deleteJson('/api/rmas/'.$rmaId)
            ->assertStatus(405);

        $fresh = Rma::query()->findOrFail($rmaId);
        $this->assertSame($original->number, $fresh->number);
        $this->assertSame(Rma::STATUS_REQUESTED, $fresh->status);
        $this->assertSame((int) $original->buyer_company_id, (int) $fresh->buyer_company_id);

        $foreignItem = ShipmentItem::query()->where('id', '!=', $itemId)->first();
        if ($foreignItem) {
            $this->actingAs($this->userA(), 'sanctum')
                ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                    'reason' => Rma::REASON_DAMAGED,
                    'items' => [['shipment_item_id' => $foreignItem->id, 'quantity' => 1]],
                ])
                ->assertUnprocessable();
        }

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.rma.buyer@example.com',
            [Permission::SHIPMENT_READ, Permission::DELIVERY_CONFIRMATION_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/rmas')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function requestedRmaContext(string $title = 'Sec RMA'): array
    {
        $shipmentId = $this->deliveredConfirmedShipment($title);
        $itemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        $rmaId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma.id');

        return [$shipmentId, $rmaId, $itemId];
    }

    private function deliveredConfirmedShipment(string $title = 'Sec RMA'): int
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
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated();

        return $shipmentId;
    }
}
