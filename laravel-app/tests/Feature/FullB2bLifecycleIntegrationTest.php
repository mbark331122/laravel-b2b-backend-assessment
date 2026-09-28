<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\DeliveryConfirmation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Refund;
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

/**
 * Sprint 16: full happy-path B2B commercial lifecycle through internal refund.
 */
class FullB2bLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_complete_b2b_flow_from_rfq_through_processed_refund(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $originalWholesale = (string) $product->wholesale_price;

        // 1–5 RFQ
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Sprint 16 Full Flow',
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
            ->assertJsonPath('rfq.company_id', $buyer->company_id)
            ->json('rfq.id');

        Rfq::query()->findOrFail($rfqId)->items()->whereNull('product_id')->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_SUBMITTED);

        // 6–7 Matching + distribution
        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertOk()
            ->assertJsonFragment(['id' => $profile->id]);

        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertCreated()
            ->assertJsonPath('distributions.0.supplier_company.id', $supplier->company_id)
            ->json('distributions.0.id');

        // 8 Supplier sees RFQ
        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonFragment(['id' => $rfqId]);

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        // 9–10 Quotation
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
            ->assertJsonPath('quotation.supplier_company_id', $supplier->company_id)
            ->json('quotation.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk()
            ->assertJsonPath('quotation.status', Quotation::STATUS_SUBMITTED);

        // 11–14 Negotiation
        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_OPEN)
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

        // Second negotiation after acceptance must be blocked (Sprint 16 hardening).
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertUnprocessable();

        // 15–17 Purchase order
        $poId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/purchase-order')
            ->assertCreated()
            ->assertJsonPath('purchase_order.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('purchase_order.supplier_company.id', $supplier->company_id)
            ->json('purchase_order.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CONFIRMED);

        $po = PurchaseOrder::query()->with('items')->findOrFail($poId);
        $poSubtotal = (string) $po->subtotal;
        $poTotal = (string) $po->total;
        $poUnitPrice = (string) $po->items->first()->unit_price;

        // 18–19 Invoice
        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->assertJsonPath('invoice.purchase_order_id', $poId)
            ->assertJsonPath('invoice.subtotal', $poSubtotal)
            ->assertJsonPath('invoice.total', $poTotal)
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_ISSUED);

        // 20–22 Payment
        $paymentId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
                'amount' => '0.01',
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.amount', $poTotal)
            ->assertJsonPath('payment.currency', 'USD')
            ->json('payment.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid')
            ->assertOk()
            ->assertJsonPath('payment.status', Payment::STATUS_PAID);

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->find($invoiceId)->status);

        // 23–26 Shipment
        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->assertJsonPath('shipment.purchase_order_id', $poId)
            ->json('shipment.id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/processing')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        // 27 Delivery confirmation
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated()
            ->assertJsonPath('delivery_confirmation.status', DeliveryConfirmation::STATUS_CONFIRMED);

        $shipmentItemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        // 28–29 RMA
        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('rma.shipment_id', $shipmentId)
            ->json('rma.id');

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_APPROVED);

        // 30–32 Return logistics
        $returnId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('return_shipment.rma_id', $rmaId)
            ->json('return_shipment.id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/return-shipments/'.$returnId.'/deliver')
            ->assertOk()
            ->assertJsonPath('return_shipment.status', ReturnShipment::STATUS_DELIVERED);

        // Delivered return cannot be duplicated (Sprint 16 hardening).
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 1]],
            ])
            ->assertUnprocessable();

        // 33–34 RMA completion
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/received')
            ->assertOk();
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/close')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_CLOSED);

        $expectedSubtotal = bcmul($poUnitPrice, '2', 2);
        $expectedTax = bcdiv(bcmul((string) $po->tax_amount, $expectedSubtotal, 4), $poSubtotal, 2);
        $expectedCreditTotal = bcadd($expectedSubtotal, $expectedTax, 2);

        // 35–36 Credit note
        $creditNote = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note', [
                'total' => '1.00',
                'currency' => 'EUR',
                'invoice_id' => 999,
                'buyer_company_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_DRAFT)
            ->assertJsonPath('credit_note.rma_id', $rmaId)
            ->assertJsonPath('credit_note.invoice_id', $invoiceId)
            ->assertJsonPath('credit_note.purchase_order_id', $poId)
            ->assertJsonPath('credit_note.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('credit_note.supplier_company.id', $supplier->company_id)
            ->assertJsonPath('credit_note.currency', 'USD')
            ->assertJsonPath('credit_note.subtotal', $expectedSubtotal)
            ->assertJsonPath('credit_note.total', $expectedCreditTotal)
            ->assertJsonPath('credit_note.items.0.unit_price_snapshot', $poUnitPrice)
            ->assertJsonPath('credit_note.items.0.quantity', 2)
            ->json('credit_note');

        $creditNoteId = $creditNote['id'];

        // Mutate live catalog/commercial data — historical credit note snapshots must hold.
        $product->wholesale_price = '99999.00';
        $product->save();
        InvoiceItem::query()->where('invoice_id', $invoiceId)->update([
            'unit_price' => '1.00',
            'line_total' => '1.00',
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/credit-notes/'.$creditNoteId)
            ->assertOk()
            ->assertJsonPath('credit_note.total', $expectedCreditTotal)
            ->assertJsonPath('credit_note.items.0.unit_price_snapshot', $poUnitPrice);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertOk()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_ISSUED);

        // 37–38 Refund
        $refund = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund', [
                'amount' => '0.01',
                'payment_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('refund.status', Refund::STATUS_PENDING)
            ->assertJsonPath('refund.credit_note_id', $creditNoteId)
            ->assertJsonPath('refund.invoice_id', $invoiceId)
            ->assertJsonPath('refund.payment_id', $paymentId)
            ->assertJsonPath('refund.amount', $expectedCreditTotal)
            ->assertJsonPath('refund.currency', 'USD')
            ->json('refund');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/refunds/'.$refund['id'].'/process')
            ->assertOk()
            ->assertJsonPath('refund.status', Refund::STATUS_PROCESSED);

        // Void after refund must be blocked (Sprint 16 hardening).
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/void', ['reason' => 'Should fail'])
            ->assertUnprocessable();

        // Historical / unrelated records unchanged
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->find($invoiceId)->status);
        $this->assertSame($poTotal, (string) Invoice::query()->find($invoiceId)->total);
        $this->assertSame(Payment::STATUS_PAID, Payment::query()->find($paymentId)->status);
        $this->assertSame($poTotal, (string) Payment::query()->find($paymentId)->amount);
        $this->assertSame(PurchaseOrder::STATUS_CONFIRMED, PurchaseOrder::query()->find($poId)->status);
        $this->assertSame($poTotal, (string) PurchaseOrder::query()->find($poId)->total);
        $this->assertSame(Shipment::STATUS_DELIVERED, Shipment::query()->find($shipmentId)->status);
        $this->assertSame(Rma::STATUS_CLOSED, Rma::query()->find($rmaId)->status);
        $this->assertSame(ReturnShipment::STATUS_DELIVERED, ReturnShipment::query()->find($returnId)->status);
        $this->assertSame($expectedCreditTotal, (string) CreditNote::query()->find($creditNoteId)->total);
        $this->assertSame($poUnitPrice, (string) CreditNoteItem::query()->where('credit_note_id', $creditNoteId)->value('unit_price_snapshot'));
        $this->assertSame('99999.00', (string) $product->fresh()->wholesale_price);
        $this->assertNotSame($originalWholesale, (string) $product->fresh()->wholesale_price);

        // Ownership chain
        foreach ([
            PurchaseOrder::query()->find($poId),
            Invoice::query()->find($invoiceId),
            Payment::query()->find($paymentId),
            Shipment::query()->find($shipmentId),
            Rma::query()->find($rmaId),
            ReturnShipment::query()->find($returnId),
            CreditNote::query()->find($creditNoteId),
            Refund::query()->find($refund['id']),
        ] as $record) {
            $this->assertSame((int) $buyer->company_id, (int) $record->buyer_company_id);
            $this->assertSame((int) $supplier->company_id, (int) $record->supplier_company_id);
        }

        // Audit trail for key transitions
        foreach ([
            AuditLog::RFQ_SUBMITTED,
            AuditLog::RFQ_DISTRIBUTED,
            AuditLog::QUOTATION_SUBMITTED,
            AuditLog::NEGOTIATION_CREATED,
            AuditLog::NEGOTIATION_OFFER_ACCEPTED,
            AuditLog::PURCHASE_ORDER_CONFIRMED,
            AuditLog::INVOICE_ISSUED,
            AuditLog::PAYMENT_MARKED_PAID,
            AuditLog::SHIPMENT_DELIVERED,
            AuditLog::DELIVERY_CONFIRMATION_CREATED,
            AuditLog::RMA_APPROVED,
            AuditLog::RMA_CLOSED,
            AuditLog::RETURN_SHIPMENT_DELIVERED,
            AuditLog::CREDIT_NOTE_CREATED,
            AuditLog::CREDIT_NOTE_ISSUED,
            AuditLog::REFUND_CREATED,
            AuditLog::REFUND_PROCESSED,
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }

    public function test_blocked_transitions_across_core_domains(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        // Draft RFQ cannot be distributed.
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Blocked Flow',
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 100,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->json('rfq.id');

        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertUnprocessable();

        // Credit note blocked before RMA closed.
        [$buyer2, $supplier2, $rmaId] = $this->approvedRmaOnly();

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        // Refund blocked on draft credit note path covered elsewhere; terminal refund mutation:
        [$buyer3, $supplier3, $refundId] = $this->processedRefundContext();

        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/cancel')
            ->assertUnprocessable();

        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/fail')
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function approvedRmaOnly(): array
    {
        [$buyer, $supplier, $shipmentId, $shipmentItemId] = $this->deliveredShipmentContext('Blocked CN');

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DEFECTIVE,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk();

        return [$buyer, $supplier, $rmaId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function processedRefundContext(): array
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();

        // Reuse full flow helper via a lightweight path: run through closed RMA with payment.
        [$buyer, $supplier, $shipmentId, $shipmentItemId, $invoiceId, $paymentId, $poId] = $this->paidDeliveredContext('Terminal Refund');

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

        $cnId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/credit-notes/'.$cnId.'/issue')->assertOk();
        $refundId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$cnId.'/refund')
            ->assertCreated()
            ->json('refund.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/refunds/'.$refundId.'/process')->assertOk();

        return [$buyer, $supplier, $refundId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function deliveredShipmentContext(string $title): array
    {
        [$buyer, $supplier, $shipmentId, $shipmentItemId] = array_slice($this->paidDeliveredContext($title), 0, 4);

        return [$buyer, $supplier, $shipmentId, $shipmentItemId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: int, 6: int}
     */
    private function paidDeliveredContext(string $title): array
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

        return [$buyer, $supplier, $shipmentId, $shipmentItemId, $invoiceId, $paymentId, $poId];
    }
}
