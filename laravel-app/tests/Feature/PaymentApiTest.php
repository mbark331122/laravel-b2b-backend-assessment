<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_creates_payment_from_issued_invoice_with_server_amount_and_number(): void
    {
        [$buyer, $supplier, $invoiceId] = $this->issuedInvoiceContext();

        $payment = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'invoice_id' => 999,
                'number' => 'CLIENT-PAY',
                'status' => Payment::STATUS_PAID,
                'amount' => 1,
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.status', Payment::STATUS_PENDING)
            ->assertJsonPath('payment.method', Payment::METHOD_BANK_TRANSFER)
            ->assertJsonPath('payment.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('payment.supplier_company.id', $supplier->company_id)
            ->json('payment');

        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $payment['number']);
        $this->assertNotSame('CLIENT-PAY', $payment['number']);
        $this->assertSame('735.00', $payment['amount']);
        $this->assertSame('USD', $payment['currency']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PAYMENT_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_CASH,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_lifecycle_mark_paid_failed_cancel_and_invoice_paid(): void
    {
        [$buyer, $supplier, $invoiceId] = $this->issuedInvoiceContext();

        $paymentId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_MANUAL,
            ])
            ->assertCreated()
            ->json('payment.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid')
            ->assertForbidden();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid')
            ->assertOk()
            ->assertJsonPath('payment.status', Payment::STATUS_PAID);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PAYMENT_MARKED_PAID,
            'company_id' => $supplier->company_id,
        ]);

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->find($invoiceId)->status);
        $this->assertNotNull(Invoice::query()->find($invoiceId)->paid_at);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-failed')
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_CASH,
            ])
            ->assertUnprocessable();

        [$buyer2, $supplier2, $invoiceId2] = $this->issuedInvoiceContext(title: 'Pay Fail');
        $failId = $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId2.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->json('payment.id');

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/payments/'.$failId.'/mark-failed')
            ->assertOk()
            ->assertJsonPath('payment.status', Payment::STATUS_FAILED);

        $retryId = $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId2.'/payment', [
                'method' => Payment::METHOD_CASH,
            ])
            ->assertCreated()
            ->json('payment.id');

        $this->assertNotSame($failId, $retryId);

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/payments/'.$retryId.'/cancel')
            ->assertOk()
            ->assertJsonPath('payment.status', Payment::STATUS_CANCELLED);

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/payments/'.$retryId.'/cancel')
            ->assertUnprocessable();
    }

    public function test_non_issued_invoice_cannot_create_payment(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $draftInvoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->json('invoice.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$draftInvoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$draftInvoiceId.'/cancel')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$draftInvoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertUnprocessable();

        [$buyer2, $supplier2, $invoiceId] = $this->issuedInvoiceContext(title: 'Void Pay');
        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/void', ['reason' => 'Void for payment test'])
            ->assertOk();

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertUnprocessable();
    }

    public function test_buyer_and_supplier_list_endpoints(): void
    {
        [$buyer, $supplier, $invoiceId] = $this->issuedInvoiceContext();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1, 'payments');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/payments')
            ->assertOk()
            ->assertJsonCount(1, 'payments');
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function issuedInvoiceContext(string $title = 'Pay RFQ'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext($title);

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_ISSUED);

        return [$buyer, $supplier, $invoiceId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'Pay RFQ'): array
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

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CONFIRMED);

        return [$buyer, $supplier, $poId];
    }
}
