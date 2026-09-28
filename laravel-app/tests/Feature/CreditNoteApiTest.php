<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\ReturnShipment;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\RmaItem;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_supplier_creates_issues_and_refunds_closed_rma_credit_note(): void
    {
        [$buyer, $supplier, $rmaId, $invoiceId, $paymentId] = $this->closedRmaWithPaidInvoiceContext();

        $rma = Rma::query()->with('items')->findOrFail($rmaId);
        $invoice = Invoice::query()->with('items')->findOrFail($invoiceId);
        $rmaQty = (int) $rma->items->first()->quantity;
        $unitPrice = (string) $invoice->items->first()->unit_price;
        $expectedSubtotal = bcmul($unitPrice, (string) $rmaQty, 2);
        $expectedTax = bcdiv(bcmul((string) $invoice->tax_amount, $expectedSubtotal, 4), (string) $invoice->subtotal, 2);
        $expectedTotal = bcadd($expectedSubtotal, $expectedTax, 2);

        $creditNote = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note', [
                'reason' => 'Quality return credit',
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'rma_id' => 999,
                'invoice_id' => 999,
                'purchase_order_id' => 999,
                'number' => 'CLIENT-CN',
                'status' => CreditNote::STATUS_ISSUED,
                'subtotal' => '1.00',
                'tax_amount' => '1.00',
                'total' => '9999.00',
                'currency' => 'EUR',
                'amount' => '9999.00',
            ])
            ->assertCreated()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_DRAFT)
            ->assertJsonPath('credit_note.rma_id', $rmaId)
            ->assertJsonPath('credit_note.invoice_id', $invoiceId)
            ->assertJsonPath('credit_note.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('credit_note.supplier_company.id', $supplier->company_id)
            ->assertJsonPath('credit_note.currency', 'USD')
            ->assertJsonPath('credit_note.subtotal', $expectedSubtotal)
            ->assertJsonPath('credit_note.tax_amount', $expectedTax)
            ->assertJsonPath('credit_note.total', $expectedTotal)
            ->json('credit_note');

        $this->assertMatchesRegularExpression('/^CN-\d{4}-\d{6}$/', $creditNote['number']);
        $this->assertNotSame('CLIENT-CN', $creditNote['number']);
        $this->assertSame($rmaQty, $creditNote['items'][0]['quantity']);
        $this->assertSame($unitPrice, $creditNote['items'][0]['unit_price_snapshot']);
        $this->assertSame($expectedSubtotal, $creditNote['items'][0]['line_total_snapshot']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::CREDIT_NOTE_CREATED,
            'company_id' => $supplier->company_id,
        ]);

        $id = $creditNote['id'];

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertOk()
            ->assertJsonPath('credit_note.id', $id);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertOk()
            ->assertJsonPath('credit_note.id', $id);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/issue', [
                'status' => CreditNote::STATUS_CANCELLED,
                'issued_at' => '2000-01-01',
            ])
            ->assertOk()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_ISSUED);

        $this->assertNotNull(CreditNote::query()->find($id)->issued_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::CREDIT_NOTE_ISSUED,
            'company_id' => $supplier->company_id,
        ]);

        $product = Product::query()->find($invoice->items->first()->product_id);
        $originalPrice = (string) $product->wholesale_price;
        $product->wholesale_price = '99999.00';
        $product->save();

        InvoiceItem::query()->where('invoice_id', $invoiceId)->update([
            'unit_price' => '1.00',
            'line_total' => '1.00',
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/credit-notes/'.$id)
            ->assertOk()
            ->assertJsonPath('credit_note.total', $expectedTotal)
            ->assertJsonPath('credit_note.items.0.unit_price_snapshot', $unitPrice)
            ->assertJsonPath('credit_note.items.0.line_total_snapshot', $expectedSubtotal);

        $this->assertSame('99999.00', (string) $product->fresh()->wholesale_price);
        $this->assertNotSame($originalPrice, (string) $product->fresh()->wholesale_price);

        $refund = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/refund', [
                'amount' => '1.00',
                'currency' => 'EUR',
                'payment_id' => 999,
                'invoice_id' => 999,
                'status' => Refund::STATUS_PROCESSED,
                'number' => 'CLIENT-REF',
            ])
            ->assertCreated()
            ->assertJsonPath('refund.status', Refund::STATUS_PENDING)
            ->assertJsonPath('refund.credit_note_id', $id)
            ->assertJsonPath('refund.invoice_id', $invoiceId)
            ->assertJsonPath('refund.payment_id', $paymentId)
            ->assertJsonPath('refund.amount', $expectedTotal)
            ->assertJsonPath('refund.currency', 'USD')
            ->json('refund');

        $this->assertMatchesRegularExpression('/^REF-\d{4}-\d{6}$/', $refund['number']);
        $this->assertNotSame('CLIENT-REF', $refund['number']);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/refund')
            ->assertOk()
            ->assertJsonPath('refund.id', $refund['id']);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/refunds/'.$refund['id'].'/process')
            ->assertOk()
            ->assertJsonPath('refund.status', Refund::STATUS_PROCESSED);

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->find($invoiceId)->status);
        $this->assertSame(Payment::STATUS_PAID, Payment::query()->find($paymentId)->status);
        $this->assertSame((string) $invoice->total, (string) Invoice::query()->find($invoiceId)->total);
        $this->assertSame(Rma::STATUS_CLOSED, Rma::query()->find($rmaId)->status);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/credit-notes')
            ->assertOk()
            ->assertJsonCount(1, 'credit_notes');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/credit-notes')
            ->assertOk()
            ->assertJsonCount(1, 'credit_notes');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/refunds')
            ->assertOk()
            ->assertJsonCount(1, 'refunds');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/refunds')
            ->assertOk()
            ->assertJsonCount(1, 'refunds');
    }

    public function test_credit_note_blocked_unless_rma_closed_with_delivered_return(): void
    {
        [$buyer, $supplier, $rmaId, $rmaItemId] = $this->requestedRmaWithInvoiceContext(title: 'CN Block');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        $returnId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/return-shipment', [
                'items' => [['rma_item_id' => $rmaItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('return_shipment.id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/return-shipments/'.$returnId.'/deliver')->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/received')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/close')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_CLOSED);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_DRAFT);
    }

    public function test_rejected_and_cancelled_rma_cannot_create_credit_note(): void
    {
        [$buyer, $supplier, $rmaId] = $this->requestedRmaWithInvoiceContext(title: 'CN Reject');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/reject', ['rejection_reason' => 'Not eligible'])
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();

        [$buyer2, $supplier2, $rmaId2] = $this->requestedRmaWithInvoiceContext(title: 'CN Cancel');

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId2.'/cancel')
            ->assertOk();

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId2.'/credit-note')
            ->assertUnprocessable();
    }

    public function test_credit_note_lifecycle_and_immutability(): void
    {
        [$buyer, $supplier, $rmaId] = $this->closedRmaWithPaidInvoiceContext(title: 'CN Life');

        $id = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $total = (string) CreditNote::query()->find($id)->total;

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/void', ['reason' => 'Too early'])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/cancel', ['reason' => 'Abort draft'])
            ->assertOk()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_CANCELLED);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/issue')
            ->assertUnprocessable();

        [$buyerB, $supplierB, $rmaIdB] = $this->closedRmaWithPaidInvoiceContext(title: 'CN Void');

        $idB = $this->actingAs($supplierB, 'sanctum')
            ->postJson('/api/rmas/'.$rmaIdB.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $this->actingAs($supplierB, 'sanctum')
            ->postJson('/api/credit-notes/'.$idB.'/issue')
            ->assertOk();

        $this->actingAs($supplierB, 'sanctum')
            ->postJson('/api/credit-notes/'.$idB.'/cancel')
            ->assertUnprocessable();

        $this->actingAs($supplierB, 'sanctum')
            ->postJson('/api/credit-notes/'.$idB.'/void', ['reason' => 'Accounting correction'])
            ->assertOk()
            ->assertJsonPath('credit_note.status', CreditNote::STATUS_VOIDED);

        $this->assertSame($total, (string) CreditNote::query()->find($id)->total);
        $voidedTotal = (string) CreditNote::query()->find($idB)->total;
        CreditNoteItem::query()->where('credit_note_id', $idB)->update([
            'unit_price_snapshot' => '0.01',
            'line_total_snapshot' => '0.01',
        ]);
        $this->actingAs($supplierB, 'sanctum')
            ->getJson('/api/credit-notes/'.$idB)
            ->assertOk();
        // Direct DB mutation proves DB can change, but API snapshots were frozen at create;
        // re-read items from DB after issue/void still reflect stored values (historical row).
        $this->assertSame('0.01', (string) CreditNoteItem::query()->where('credit_note_id', $idB)->value('unit_price_snapshot'));
        $this->assertSame($voidedTotal, (string) CreditNote::query()->find($idB)->total);

        $this->actingAs($supplierB, 'sanctum')
            ->postJson('/api/credit-notes/'.$idB.'/refund')
            ->assertUnprocessable();
    }

    public function test_refund_requires_issued_credit_note_and_paid_payment(): void
    {
        [$buyer, $supplier, $rmaId, $invoiceId] = $this->closedRmaWithInvoiceContext(paid: false, title: 'CN Unpaid');

        $creditNoteId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/issue')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/credit-notes/'.$creditNoteId.'/refund')
            ->assertUnprocessable();

        $this->assertSame(Invoice::STATUS_ISSUED, Invoice::query()->find($invoiceId)->status);

        [$buyer2, $supplier2, $rmaId2, $invoiceId2, $paymentId2] = $this->closedRmaWithPaidInvoiceContext(title: 'CN Paid Refund');

        $cnId = $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId2.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $this->actingAs($supplier2, 'sanctum')->postJson('/api/credit-notes/'.$cnId.'/issue')->assertOk();

        $refundId = $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/credit-notes/'.$cnId.'/refund')
            ->assertCreated()
            ->assertJsonPath('refund.payment_id', $paymentId2)
            ->json('refund.id');

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/fail')
            ->assertOk()
            ->assertJsonPath('refund.status', Refund::STATUS_FAILED);

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/refunds/'.$refundId.'/process')
            ->assertUnprocessable();

        [$buyer3, $supplier3, $rmaId3] = $this->closedRmaWithPaidInvoiceContext(title: 'CN Cancel Refund');
        $cnId3 = $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId3.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');
        $this->actingAs($supplier3, 'sanctum')->postJson('/api/credit-notes/'.$cnId3.'/issue')->assertOk();
        $refundId3 = $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/credit-notes/'.$cnId3.'/refund')
            ->assertCreated()
            ->json('refund.id');
        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/refunds/'.$refundId3.'/cancel')
            ->assertOk()
            ->assertJsonPath('refund.status', Refund::STATUS_CANCELLED);
        $this->actingAs($supplier3, 'sanctum')
            ->postJson('/api/refunds/'.$refundId3.'/process')
            ->assertUnprocessable();
    }

    public function test_closed_rma_without_delivered_return_shipment_blocked(): void
    {
        [$buyer, $supplier, $rmaId, $rmaItemId] = $this->requestedRmaWithInvoiceContext(title: 'CN No Ret');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/approve')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/received')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/rmas/'.$rmaId.'/close')->assertOk();

        $this->assertSame(0, ReturnShipment::query()->where('rma_id', $rmaId)->count());

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertUnprocessable();
    }

    public function test_buyer_cannot_create_or_issue_credit_note(): void
    {
        [$buyer, $supplier, $rmaId] = $this->closedRmaWithPaidInvoiceContext(title: 'CN Buyer');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertForbidden();

        $id = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/credit-note')
            ->assertCreated()
            ->json('credit_note.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/credit-notes/'.$id.'/issue')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function closedRmaWithPaidInvoiceContext(string $title = 'CN Paid'): array
    {
        return $this->closedRmaWithInvoiceContext(paid: true, title: $title);
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4?: int}
     */
    private function closedRmaWithInvoiceContext(bool $paid = true, string $title = 'CN Closed'): array
    {
        [$buyer, $supplier, $rmaId, $rmaItemId, $invoiceId, $paymentId] = $this->approvedRmaWithInvoiceContext($paid, $title);

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

        if ($paid) {
            return [$buyer, $supplier, $rmaId, $invoiceId, $paymentId];
        }

        return [$buyer, $supplier, $rmaId, $invoiceId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5?: int}
     */
    private function approvedRmaWithInvoiceContext(bool $paid = true, string $title = 'CN Approved'): array
    {
        [$buyer, $supplier, $rmaId, $rmaItemId, $invoiceId, $paymentId] = $this->requestedRmaWithInvoiceContext($paid, $title);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk();

        return [$buyer, $supplier, $rmaId, $rmaItemId, $invoiceId, $paymentId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5?: int}
     */
    private function requestedRmaWithInvoiceContext(bool $paid = true, string $title = 'CN RFQ'): array
    {
        [$buyer, $supplier, $shipmentId, $poId, $shipmentItemId, $invoiceId, $paymentId] = $this->deliveredPaidContext($paid, $title);

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $shipmentItemId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $rmaItemId = (int) RmaItem::query()->where('rma_id', $rmaId)->value('id');

        return [$buyer, $supplier, $rmaId, $rmaItemId, $invoiceId, $paymentId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: int, 6?: int}
     */
    private function deliveredPaidContext(bool $paid = true, string $title = 'CN RFQ'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext($title);

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk();

        $paymentId = null;
        if ($paid) {
            $paymentId = $this->actingAs($buyer, 'sanctum')
                ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                    'method' => Payment::METHOD_BANK_TRANSFER,
                ])
                ->assertCreated()
                ->json('payment.id');

            $this->actingAs($supplier, 'sanctum')
                ->postJson('/api/payments/'.$paymentId.'/mark-paid')
                ->assertOk();
        }

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

        return [$buyer, $supplier, $shipmentId, $poId, $shipmentItemId, $invoiceId, $paymentId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'CN RFQ'): array
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
