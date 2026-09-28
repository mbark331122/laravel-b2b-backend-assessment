<?php

namespace Tests\Feature\Security;

use App\Models\CreditNote;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;

class CreditNoteSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$rmaId, $creditNoteId, $refundId] = $this->issuedCreditNoteWithRefundContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/credit-notes/'.$creditNoteId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/refunds/'.$refundId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertForbidden();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/credit-notes/'.$creditNoteId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/refunds/'.$refundId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/process')
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/process')
            ->assertForbidden();
    }

    public function test_spoofing_immutability_and_permissions(): void
    {
        [$rmaId, $creditNoteId] = $this->draftCreditNoteContext();

        $original = CreditNote::query()->findOrFail($creditNoteId);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->putJson('/api/credit-notes/'.$creditNoteId, [
                'status' => CreditNote::STATUS_ISSUED,
                'total' => '1.00',
            ])
            ->assertStatus(405);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->deleteJson('/api/credit-notes/'.$creditNoteId)
            ->assertStatus(405);

        $fresh = CreditNote::query()->findOrFail($creditNoteId);
        $this->assertSame($original->number, $fresh->number);
        $this->assertSame($original->total, $fresh->total);
        $this->assertSame(CreditNote::STATUS_DRAFT, $fresh->status);
        $this->assertSame((int) $original->buyer_company_id, (int) $fresh->buyer_company_id);
        $this->assertSame((int) $original->supplier_company_id, (int) $fresh->supplier_company_id);
        $this->assertSame((int) $original->invoice_id, (int) $fresh->invoice_id);
        $this->assertSame((int) $original->rma_id, (int) $fresh->rma_id);

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.cn.supplier@example.com',
            [Permission::RMA_READ, Permission::INVOICE_READ, Permission::PAYMENT_READ]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertForbidden();

        $this->actingAs($limitedSupplier, 'sanctum')
            ->getJson('/api/supplier/credit-notes')
            ->assertForbidden();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.cn.buyer@example.com',
            [Permission::RMA_READ, Permission::INVOICE_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/credit-notes')
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/refunds')
            ->assertForbidden();
    }

    public function test_cross_company_spoofed_ids_are_ignored(): void
    {
        [$rmaId, $creditNoteId, $invoiceId, $paymentId] = $this->draftCreditNoteContextWithIds();

        $otherPo = PurchaseOrder::query()
            ->where('id', '!=', CreditNote::query()->find($creditNoteId)->purchase_order_id)
            ->first();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note', [
                'invoice_id' => $invoiceId + 999,
                'purchase_order_id' => $otherPo?->id ?? 999,
                'buyer_company_id' => $this->userB()->company_id,
                'supplier_company_id' => $this->supplierUserB()->company_id,
                'payment_id' => $paymentId + 999,
                'rma_id' => $rmaId + 999,
            ])
            ->assertOk()
            ->assertJsonPath('credit_note.id', $creditNoteId)
            ->assertJsonPath('credit_note.invoice_id', $invoiceId)
            ->assertJsonPath('credit_note.buyer_company.id', $this->userA()->company_id)
            ->assertJsonPath('credit_note.supplier_company.id', $this->supplierUser()->company_id);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertOk();

        $refund = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund', [
                'payment_id' => $paymentId + 999,
                'invoice_id' => $invoiceId + 999,
                'buyer_company_id' => $this->userB()->company_id,
                'supplier_company_id' => $this->supplierUserB()->company_id,
                'amount' => '0.01',
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonPath('refund.payment_id', $paymentId)
            ->assertJsonPath('refund.invoice_id', $invoiceId)
            ->assertJsonPath('refund.buyer_company.id', $this->userA()->company_id)
            ->json('refund');

        $this->assertNotSame('0.01', $refund['amount']);
        $this->assertSame('USD', $refund['currency']);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function issuedCreditNoteWithRefundContext(string $title = 'Sec CN'): array
    {
        [$rmaId, $creditNoteId] = $this->draftCreditNoteContext($title);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertOk();

        $refundId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertCreated()
            ->json('refund.id');

        return [$rmaId, $creditNoteId, $refundId];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function draftCreditNoteContext(string $title = 'Sec CN Draft'): array
    {
        [$rmaId, $creditNoteId] = $this->draftCreditNoteContextWithIds($title);

        return [$rmaId, $creditNoteId];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function draftCreditNoteContextWithIds(string $title = 'Sec CN Draft'): array
    {
        [$rmaId, $rmaItemId, $invoiceId, $paymentId] = $this->closedRmaPaidContext($title);

        $creditNoteId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        return [$rmaId, $creditNoteId, $invoiceId, $paymentId];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function closedRmaPaidContext(string $title = 'Sec CN'): array
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

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/invoices/'.$invoiceId.'/issue')->assertOk();

        $paymentId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', ['method' => Payment::METHOD_BANK_TRANSFER])
            ->assertCreated()
            ->json('payment.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/payments/'.$paymentId.'/mark-paid')->assertOk();

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->json('shipment.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/processing')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/deliver')->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')->assertCreated();

        $shipmentItemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/approve')->assertOk();

        $returnId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('return_shipment.id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/deliver')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/received')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/close')->assertOk();

        return [$rmaId, $rmaItemId, $invoiceId, $paymentId];
    }
}
