<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;

class PaymentSecurityTest extends SecurityTestCase
{
    public function test_buyer_and_supplier_isolation(): void
    {
        [$invoiceId, $paymentId] = $this->pendingPaymentContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/payments/'.$paymentId)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/cancel')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/payments/'.$paymentId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid')
            ->assertNotFound();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_CASH,
            ])
            ->assertForbidden();
    }

    public function test_spoofing_ignored_and_permissions_enforced(): void
    {
        [$invoiceId, $paymentId] = $this->pendingPaymentContext();

        $payment = Payment::query()->findOrFail($paymentId);
        $originalAmount = (string) $payment->amount;
        $originalNumber = $payment->number;

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid', [
                'status' => Payment::STATUS_PAID,
                'amount' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->find($paymentId)->status);
        $this->assertSame($originalAmount, (string) Payment::query()->find($paymentId)->amount);
        $this->assertSame($originalNumber, Payment::query()->find($paymentId)->number);

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.pay.buyer@example.com',
            [Permission::INVOICE_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/payments')
            ->assertForbidden();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.pay.supplier@example.com',
            [Permission::INVOICE_READ, Permission::PAYMENT_READ]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->postJson('/api/payments/'.$paymentId.'/mark-paid')
            ->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pendingPaymentContext(string $title = 'Sec Pay'): array
    {
        $invoiceId = $this->issuedInvoiceOnly($title);

        $paymentId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertCreated()
            ->json('payment.id');

        return [$invoiceId, $paymentId];
    }

    private function issuedInvoiceOnly(string $title = 'Sec Pay'): int
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

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_ISSUED);

        return $invoiceId;
    }
}
