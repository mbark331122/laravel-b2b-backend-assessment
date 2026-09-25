<?php

namespace Tests\Feature\Security;

use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;

class PurchaseOrderSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$negotiationId, $poId] = $this->draftPoContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/purchase-orders/'.$poId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/purchase-orders/'.$poId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/reject', [
                'reason' => 'Nope',
            ])
            ->assertNotFound();
    }

    public function test_spoofing_and_non_accepted_negotiation_blocked(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(5)->toDateString(),
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 12,
                ]],
            ])
            ->json('quotation.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        $negotiationId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->json('negotiation.id');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order', [
                'status' => 'confirmed',
            ])
            ->assertUnprocessable();

        foreach ([Negotiation::STATUS_REJECTED, Negotiation::STATUS_WITHDRAWN, Negotiation::STATUS_EXPIRED] as $status) {
            Negotiation::query()->whereKey($negotiationId)->update([
                'status' => $status,
                'active_lock' => null,
                'valid_until' => $status === Negotiation::STATUS_EXPIRED ? now()->subDay()->toDateString() : now()->addDays(5)->toDateString(),
            ]);

            $this->actingAs($this->userA(), 'sanctum')
                ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
                ->assertUnprocessable();
        }
    }

    public function test_missing_permissions_forbidden(): void
    {
        [$negotiationId, $poId] = $this->draftPoContext();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.po.buyer@example.com',
            [Permission::RFQ_READ, Permission::NEGOTIATION_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/purchase-orders')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.po.supplier@example.com',
            [Permission::SUPPLIER_RFQ_READ, Permission::NEGOTIATION_READ]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function draftPoContext(): array
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
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

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        $negotiationId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->json('negotiation.id');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 11,
                ]],
            ])
            ->assertCreated();

        $offerId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 11,
                ]],
            ])
            ->json('offer.id');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$offerId.'/accept')
            ->assertOk();

        $poId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertCreated()
            ->json('purchase_order.id');

        return [$negotiationId, $poId];
    }

    private function createSubmittedRfq(User $buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Secure PO RFQ',
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

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk();

        return $rfqId;
    }
}
