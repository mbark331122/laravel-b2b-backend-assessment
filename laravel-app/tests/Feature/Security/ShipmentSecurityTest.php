<?php

namespace Tests\Feature\Security;

use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Shipment;
use App\Models\SupplierProfile;

class ShipmentSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$poId, $shipmentId] = $this->pendingShipmentContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/shipments/'.$shipmentId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/processing')
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/shipments/'.$shipmentId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/ship')
            ->assertNotFound();
    }

    public function test_spoofing_ignored_po_states_and_permissions(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly();

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment', [
                'number' => 'HACK',
                'status' => Shipment::STATUS_DELIVERED,
                'items' => [['quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('shipment.status', Shipment::STATUS_PENDING)
            ->json('shipment.id');

        $this->assertNotSame('HACK', Shipment::query()->find($shipmentId)->number);

        PurchaseOrder::query()->whereKey($poId)->update(['status' => PurchaseOrder::STATUS_REJECTED]);
        // Existing shipment remains; rejected PO cannot create a new one:
        [$buyer2, $supplier2, $poReject] = $this->confirmedPoOnly(title: 'Reject Ship');
        PurchaseOrder::query()->whereKey($poReject)->update(['status' => PurchaseOrder::STATUS_REJECTED]);

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poReject.'/shipment')
            ->assertUnprocessable();

        [$buyer3, $supplier3, $poCancel] = $this->confirmedPoOnly(title: 'Cancel Ship PO');
        PurchaseOrder::query()->whereKey($poCancel)->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poCancel.'/shipment')
            ->assertUnprocessable();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.shp.supplier@example.com',
            [Permission::PURCHASE_ORDER_READ, Permission::PURCHASE_ORDER_CONFIRM]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertForbidden();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.shp.buyer@example.com',
            [Permission::PURCHASE_ORDER_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/shipments')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pendingShipmentContext(string $title = 'Sec Ship'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly($title);

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->json('shipment.id');

        return [$poId, $shipmentId];
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: int}
     */
    private function confirmedPoOnly(string $title = 'Sec Ship'): array
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

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk();

        return [$buyer, $supplier, $poId];
    }
}
