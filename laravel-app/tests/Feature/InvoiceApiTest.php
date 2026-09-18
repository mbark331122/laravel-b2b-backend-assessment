<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_supplier_creates_invoice_from_confirmed_po_with_snapshot_and_number(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $invoice = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice', [
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'number' => 'CLIENT-INV',
                'status' => Invoice::STATUS_ISSUED,
                'subtotal' => 1,
                'total' => 1,
                'tax_amount' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->assertJsonPath('invoice.purchase_order_id', $poId)
            ->assertJsonPath('invoice.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('invoice.supplier_company.id', $supplier->company_id)
            ->json('invoice');

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $invoice['number']);
        $this->assertNotSame('CLIENT-INV', $invoice['number']);
        $this->assertSame('735.00', $invoice['total']);
        $this->assertSame('5.00', $invoice['tax_amount']);
        $this->assertCount(1, $invoice['items']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::INVOICE_CREATED,
            'company_id' => $supplier->company_id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertOk()
            ->assertJsonPath('invoice.id', $invoice['id']);

        $this->assertDatabaseCount('invoices', 1);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertOk()
            ->assertJsonPath('invoice.id', $invoice['id']);
    }

    public function test_issue_void_cancel_lifecycle_and_snapshot_integrity(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $before = Invoice::query()->with('items')->findOrFail($invoiceId);
        $snapshotTotal = (string) $before->total;
        $snapshotDesc = $before->items->first()->description;

        Product::query()->where('sku', 'SUG-45')->update([
            'name' => 'Mutated Product',
            'wholesale_price' => '1.00',
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/void', ['reason' => 'too early'])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_ISSUED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::INVOICE_ISSUED,
            'company_id' => $supplier->company_id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/cancel')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/void', [])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/void', ['reason' => 'Billing error'])
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_VOIDED)
            ->assertJsonPath('invoice.void_reason', 'Billing error');

        $after = Invoice::query()->with('items')->findOrFail($invoiceId);
        $this->assertSame($snapshotTotal, (string) $after->total);
        $this->assertSame($snapshotDesc, $after->items->first()->description);
        $this->assertNotSame('Mutated Product', $after->items->first()->description);

        [$buyer2, $supplier2, $poId2] = $this->confirmedPoContext(title: 'Cancel Inv');
        $cancelId = $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId2.'/invoice')
            ->json('invoice.id');

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/invoices/'.$cancelId.'/cancel', ['reason' => 'Not needed'])
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_CANCELLED);

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/invoices/'.$cancelId.'/issue')
            ->assertUnprocessable();
    }

    public function test_non_confirmed_po_cannot_create_invoice(): void
    {
        [$buyer, $supplier, $poId] = $this->draftPoContext();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertUnprocessable();
    }

    public function test_buyer_and_supplier_list_endpoints(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'invoices');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'invoices');
    }

    public function test_paid_status_cannot_be_set_by_client(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice', [
                'status' => Invoice::STATUS_PAID,
            ])
            ->assertCreated()
            ->assertJsonPath('invoice.status', Invoice::STATUS_DRAFT)
            ->json('invoice.id');

        $this->assertNull(Invoice::query()->find($invoiceId)->paid_at);
        $this->assertNotSame(Invoice::STATUS_PAID, Invoice::query()->find($invoiceId)->status);
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'Inv RFQ'): array
    {
        [$buyer, $supplier, $poId] = $this->draftPoContext($title);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk()
            ->assertJsonPath('purchase_order.status', PurchaseOrder::STATUS_CONFIRMED);

        return [$buyer, $supplier, $poId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function draftPoContext(string $title = 'Inv RFQ'): array
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

        return [$buyer, $supplier, $poId];
    }
}
