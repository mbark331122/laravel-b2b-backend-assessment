<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryConfirmation;
use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\Shipment;
use App\Models\SupplierProfile;

class DeliveryConfirmationSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$shipmentId, $confirmationId] = $this->confirmedDeliveryContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/delivery-confirmations/'.$confirmationId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/delivery-confirmations/'.$confirmationId)
            ->assertNotFound();
    }

    public function test_spoofing_ignored_and_permissions_enforced(): void
    {
        [$shipmentId, $confirmationId] = $this->confirmedDeliveryContext();

        $original = DeliveryConfirmation::query()->findOrFail($confirmationId);

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/delivery-confirmations/'.$confirmationId, [
                'notes' => 'hack',
                'status' => 'rejected',
            ])
            ->assertStatus(405);

        $this->actingAs($this->userA(), 'sanctum')
            ->patchJson('/api/delivery-confirmations/'.$confirmationId, [
                'buyer_company_id' => 1,
            ])
            ->assertStatus(405);

        $this->actingAs($this->userA(), 'sanctum')
            ->deleteJson('/api/delivery-confirmations/'.$confirmationId)
            ->assertStatus(405);

        $fresh = DeliveryConfirmation::query()->findOrFail($confirmationId);
        $this->assertSame($original->number, $fresh->number);
        $this->assertSame($original->status, $fresh->status);
        $this->assertSame((int) $original->confirmed_by_user_id, (int) $fresh->confirmed_by_user_id);
        $this->assertSame((int) $original->buyer_company_id, (int) $fresh->buyer_company_id);

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.del.buyer@example.com',
            [Permission::SHIPMENT_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/delivery-confirmations')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function confirmedDeliveryContext(string $title = 'Sec Del'): array
    {
        $shipmentId = $this->deliveredShipmentOnly($title);

        $confirmationId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated()
            ->json('delivery_confirmation.id');

        return [$shipmentId, $confirmationId];
    }

    private function deliveredShipmentOnly(string $title = 'Sec Del'): int
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

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->json('shipment.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/processing')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/ship')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        return $shipmentId;
    }
}
