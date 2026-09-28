<?php

namespace Tests\Feature\Security;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;

/**
 * Sprint 16 final security integration suite across the commercial chain.
 */
class FinalB2bSecurityIntegrationTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation_across_commercial_chain(): void
    {
        $ids = $this->fullChainThroughRefund();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/purchase-orders/'.$ids['po_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/invoices/'.$ids['invoice_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/payments/'.$ids['payment_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/shipments/'.$ids['shipment_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rmas/'.$ids['rma_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/return-shipments/'.$ids['return_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/credit-notes/'.$ids['credit_note_id'])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/refunds/'.$ids['refund_id'])
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/invoices/'.$ids['invoice_id'])
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/credit-notes/'.$ids['credit_note_id'])
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/credit-notes/'.$ids['credit_note_id'].'/void', ['reason' => 'hijack'])
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/refunds/'.$ids['refund_id'].'/process')
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note')
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/credit-notes/'.$ids['credit_note_id'].'/refund')
            ->assertForbidden();
    }

    public function test_ownership_spoofing_and_financial_injection_ignored(): void
    {
        $ids = $this->closedRmaReadyForCreditNote();

        $creditNote = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note', [
                'company_id' => $this->userB()->company_id,
                'tenant_id' => $this->userB()->company_id,
                'user_id' => $this->userB()->id,
                'owner_id' => $this->userB()->id,
                'buyer_company_id' => $this->userB()->company_id,
                'supplier_company_id' => $this->supplierUserB()->company_id,
                'buyer_id' => $this->userB()->id,
                'supplier_id' => $this->supplierUserB()->id,
                'invoice_id' => 999999,
                'payment_id' => 999999,
                'purchase_order_id' => 999999,
                'rma_id' => 999999,
                'shipment_id' => 999999,
                'return_shipment_id' => 999999,
                'credit_note_id' => 999999,
                'number' => 'SPOOF-CN',
                'status' => CreditNote::STATUS_ISSUED,
                'subtotal' => '0.01',
                'tax_amount' => '0.01',
                'total' => '0.01',
                'amount' => '0.01',
                'currency' => 'EUR',
                'issued_at' => '2000-01-01T00:00:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('credit_note.buyer_company.id', $this->userA()->company_id)
            ->assertJsonPath('credit_note.supplier_company.id', $this->supplierUser()->company_id)
            ->assertJsonPath('credit_note.invoice_id', $ids['invoice_id'])
            ->assertJsonPath('credit_note.purchase_order_id', $ids['po_id'])
            ->assertJsonPath('credit_note.rma_id', $ids['rma_id'])
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_DRAFT)
            ->assertJsonPath('credit_note.currency', 'USD')
            ->json('credit_note');

        $this->assertNotSame('SPOOF-CN', $creditNote['number']);
        $this->assertNotSame('0.01', $creditNote['total']);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNote['id'].'/issue')
            ->assertOk();

        $refund = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNote['id'].'/refund', [
                'payment_id' => 999999,
                'invoice_id' => 999999,
                'amount' => '0.01',
                'currency' => 'EUR',
                'status' => Refund::STATUS_PROCESSED,
                'number' => 'SPOOF-REF',
                'buyer_company_id' => $this->userB()->company_id,
                'supplier_company_id' => $this->supplierUserB()->company_id,
            ])
            ->assertCreated()
            ->assertJsonPath('refund.payment_id', $ids['payment_id'])
            ->assertJsonPath('refund.invoice_id', $ids['invoice_id'])
            ->assertJsonPath('refund.status', Refund::STATUS_PENDING)
            ->assertJsonPath('refund.currency', 'USD')
            ->json('refund');

        $this->assertNotSame('SPOOF-REF', $refund['number']);
        $this->assertNotSame('0.01', $refund['amount']);
    }

    public function test_duplicate_idempotency_and_terminal_mutations(): void
    {
        $ids = $this->closedRmaReadyForCreditNote();

        $first = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note')
            ->assertCreated()
            ->json('credit_note');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note')
            ->assertOk()
            ->assertJsonPath('credit_note.id', $first['id']);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$first['id'].'/issue')
            ->assertOk();

        $refund = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$first['id'].'/refund')
            ->assertCreated()
            ->json('refund');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$first['id'].'/refund')
            ->assertOk()
            ->assertJsonPath('refund.id', $refund['id']);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/refunds/'.$refund['id'].'/process')
            ->assertOk();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/refunds/'.$refund['id'].'/fail')
            ->assertUnprocessable();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->putJson('/api/credit-notes/'.$first['id'], ['status' => CreditNote::STATUS_CANCELLED])
            ->assertStatus(405);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->deleteJson('/api/refunds/'.$refund['id'])
            ->assertStatus(405);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$ids['po_id'].'/invoice')
            ->assertOk()
            ->assertJsonPath('invoice.id', $ids['invoice_id']);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$ids['po_id'].'/shipment')
            ->assertOk()
            ->assertJsonPath('shipment.id', $ids['shipment_id']);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/shipments/'.$ids['shipment_id'].'/delivery-confirmation')
            ->assertOk();
    }

    public function test_missing_permissions_return_403(): void
    {
        $ids = $this->closedRmaReadyForCreditNote();

        $limited = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'limited.s16@example.com',
            [Permission::RMA_READ, Permission::INVOICE_READ]
        );

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/supplier/credit-notes')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/supplier/refunds')
            ->assertForbidden();
    }

    public function test_invoice_void_blocked_when_pending_payment_exists(): void
    {
        $ids = $this->issuedInvoiceWithPendingPayment();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/invoices/'.$ids['invoice_id'].'/void', ['reason' => 'Cannot void with pending payment'])
            ->assertUnprocessable();

        $this->assertSame(Invoice::STATUS_ISSUED, Invoice::query()->find($ids['invoice_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->find($ids['payment_id'])->status);
    }

    /**
     * @return array<string, int>
     */
    private function fullChainThroughRefund(): array
    {
        $ids = $this->closedRmaReadyForCreditNote();

        $creditNoteId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rmas/'.$ids['rma_id'].'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertOk();

        $refundId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertCreated()
            ->json('refund.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/process')
            ->assertOk();

        return $ids + [
            'credit_note_id' => $creditNoteId,
            'refund_id' => $refundId,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function closedRmaReadyForCreditNote(string $title = 'Sec S16'): array
    {
        $ids = $this->paidDeliveredChain($title);

        $rmaId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/shipments/'.$ids['shipment_id'].'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $ids['shipment_item_id'], 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk();

        $returnId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('return_shipment.id');

        $this->actingAs($this->supplierUser(), 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/ship')->assertOk();
        $this->actingAs($this->supplierUser(), 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/deliver')->assertOk();
        $this->actingAs($this->supplierUser(), 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/received')->assertOk();
        $this->actingAs($this->supplierUser(), 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/close')->assertOk();

        return $ids + [
            'rma_id' => $rmaId,
            'rma_item_id' => $rmaItemId,
            'return_id' => $returnId,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function issuedInvoiceWithPendingPayment(string $title = 'Sec Void'): array
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
                'items' => [['product_id' => $product->id, 'quantity' => 1000]],
            ])
            ->assertCreated()
            ->json('rfq.id');
        Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();
        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', ['supplier_profile_ids' => [$profile->id]])
            ->assertCreated()
            ->json('distributions.0.id');
        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');
        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(10)->toDateString(),
                'shipping_amount' => 50,
                'tax_amount' => 25,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 100, 'unit_price' => 10.5]],
            ])
            ->assertCreated()
            ->json('quotation.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/quotations/'.$quotationId.'/submit')->assertOk();
        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
            ])
            ->assertCreated();
        $offerId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
            ])
            ->assertCreated()
            ->json('offer.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$offerId.'/accept')
            ->assertOk();
        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertCreated()
            ->json('purchase_order.id');
        $this->actingAs($buyer, 'sanctum')->postJson('/api/purchase-orders/'.$poId.'/submit')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')->assertOk();
        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/invoices/'.$invoiceId.'/issue')->assertOk();
        $paymentId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', ['method' => Payment::METHOD_BANK_TRANSFER])
            ->assertCreated()
            ->json('payment.id');

        return [
            'po_id' => $poId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function paidDeliveredChain(string $title): array
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
                'items' => [['product_id' => $product->id, 'quantity' => 1000]],
            ])
            ->assertCreated()
            ->json('rfq.id');
        Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();
        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', ['supplier_profile_ids' => [$profile->id]])
            ->assertCreated()
            ->json('distributions.0.id');
        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');
        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(10)->toDateString(),
                'shipping_amount' => 50,
                'tax_amount' => 25,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 100, 'unit_price' => 10.5]],
            ])
            ->assertCreated()
            ->json('quotation.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/quotations/'.$quotationId.'/submit')->assertOk();
        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
            ])
            ->assertCreated();
        $offerId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
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
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);
        $this->actingAs($buyer, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')->assertCreated();
        $shipmentItemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        return [
            'rfq_id' => $rfqId,
            'po_id' => $poId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'shipment_id' => $shipmentId,
            'shipment_item_id' => $shipmentItemId,
        ];
    }
}
