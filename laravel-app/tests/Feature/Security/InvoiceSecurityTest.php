<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;

class InvoiceSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$poId, $invoiceId] = $this->issuedInvoiceContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/invoices/'.$invoiceId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertForbidden();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertForbidden();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/void', ['reason' => 'hack'])
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/invoices/'.$invoiceId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertNotFound();
    }

    public function test_spoofing_ignored_and_rejected_cancelled_po_blocked(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly();

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice', [
                'number' => 'HACK',
                'status' => Invoice::STATUS_PAID,
                'total' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->json('invoice.id');

        $this->assertNotSame('HACK', Invoice::query()->find($invoiceId)->number);

        PurchaseOrder::query()->whereKey($poId)->update(['status' => PurchaseOrder::STATUS_REJECTED]);
        // Existing invoice remains; new PO path for rejected:
        [$buyer2, $supplier2, $poReject] = $this->confirmedPoOnly(title: 'Reject PO');
        PurchaseOrder::query()->whereKey($poReject)->update(['status' => PurchaseOrder::STATUS_REJECTED]);

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poReject.'/invoice')
            ->assertUnprocessable();

        [$buyer3, $supplier3, $poCancel] = $this->confirmedPoOnly(title: 'Cancel PO');
        PurchaseOrder::query()->whereKey($poCancel)->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poCancel.'/invoice')
            ->assertUnprocessable();
    }

    public function test_missing_permissions_forbidden(): void
    {
        [$poId, $invoiceId] = $this->draftInvoiceContext();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.inv.supplier@example.com',
            [Permission::PURCHASE_ORDER_READ, Permission::PURCHASE_ORDER_CONFIRM]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertForbidden();

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertForbidden();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.inv.buyer@example.com',
            [Permission::PURCHASE_ORDER_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/invoices')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function issuedInvoiceContext(): array
    {
        [$poId, $invoiceId] = $this->draftInvoiceContext();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk();

        return [$poId, $invoiceId];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function draftInvoiceContext(): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly();

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        return [$poId, $invoiceId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoOnly(string $title = 'Sec Inv'): array
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
