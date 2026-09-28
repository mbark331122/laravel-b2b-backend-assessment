<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RmaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_creates_rma_after_delivery_confirmation_with_lifecycle(): void
    {
        [$buyer, $supplier, $shipmentId, $poId, $itemId] = $this->confirmedDeliveryContext();

        $rma = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'notes' => 'Outer packaging damaged',
                'items' => [[
                    'shipment_item_id' => $itemId,
                    'quantity' => 2,
                ]],
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'number' => 'CLIENT-RMA',
                'status' => Rma::STATUS_APPROVED,
            ])
            ->assertCreated()
            ->assertJsonPath('rma.status', Rma::STATUS_REQUESTED)
            ->assertJsonPath('rma.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('rma.supplier_company.id', $supplier->company_id)
            ->assertJsonPath('rma.purchase_order_id', $poId)
            ->json('rma');

        $this->assertMatchesRegularExpression('/^RMA-\d{4}-\d{6}$/', $rma['number']);
        $this->assertNotSame('CLIENT-RMA', $rma['number']);
        $this->assertSame(2, $rma['items'][0]['quantity']);
        $this->assertSame($itemId, $rma['items'][0]['shipment_item_id']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RMA_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $rmaId = $rma['id'];

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DEFECTIVE,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/approve')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_APPROVED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rmas/'.$rmaId.'/cancel')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/received')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_RECEIVED);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/close')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_CLOSED);

        $this->assertSame(Shipment::STATUS_DELIVERED, Shipment::query()->find($shipmentId)->status);
        $this->assertSame(PurchaseOrder::STATUS_CONFIRMED, PurchaseOrder::query()->find($poId)->status);

        $retry = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_QUALITY_ISSUE,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma');

        $this->assertNotSame($rmaId, $retry['id']);
    }

    public function test_reject_cancel_and_eligibility_rules(): void
    {
        [$buyer, $supplier, $shipmentId, $poId, $itemId] = $this->confirmedDeliveryContext(title: 'RMA Reject');

        $rmaId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_INCORRECT_ITEM,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/reject', [])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rmas/'.$rmaId.'/reject', [
                'rejection_reason' => 'Not eligible under warranty',
            ])
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_REJECTED);

        [$buyer2, $supplier2, $shipmentId2, $poId2, $itemId2] = $this->confirmedDeliveryContext(title: 'RMA Cancel');
        $cancelId = $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId2.'/rma', [
                'reason' => Rma::REASON_OTHER,
                'notes' => 'Changed mind',
                'items' => [['shipment_item_id' => $itemId2, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('rma.id');

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/rmas/'.$cancelId.'/cancel')
            ->assertOk()
            ->assertJsonPath('rma.status', Rma::STATUS_CANCELLED);

        [$buyer3, $supplier3, $pendingShipmentId] = $this->pendingShipmentContext(title: 'No Del');
        $this->actingAs($buyer3, 'sanctum')
            ->postJson('/api/shipments/'.$pendingShipmentId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => ShipmentItem::query()->where('shipment_id', $pendingShipmentId)->value('id'), 'quantity' => 1]],
            ])
            ->assertUnprocessable();

        [$buyer4, $supplier4, $deliveredNoConfirmId, $poId4, $itemId4] = $this->deliveredWithoutConfirmation(title: 'No Confirm');
        $this->actingAs($buyer4, 'sanctum')
            ->postJson('/api/shipments/'.$deliveredNoConfirmId.'/rma', [
                'reason' => Rma::REASON_DAMAGED,
                'items' => [['shipment_item_id' => $itemId4, 'quantity' => 1]],
            ])
            ->assertUnprocessable();
    }

    public function test_quantity_validation_and_financial_independence(): void
    {
        [$buyer, $supplier, $shipmentId, $poId, $itemId] = $this->confirmedDeliveryContext(title: 'RMA Qty');
        $shippedQty = (int) ShipmentItem::query()->find($itemId)->quantity;

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_INCORRECT_QUANTITY,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => $shippedQty + 1]],
            ])
            ->assertUnprocessable();

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk();

        $paymentId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertCreated()
            ->json('payment.id');

        $invoiceStatus = Invoice::query()->find($invoiceId)->status;
        $paymentStatus = Payment::query()->find($paymentId)->status;

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/rma', [
                'reason' => Rma::REASON_DEFECTIVE,
                'items' => [['shipment_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame($invoiceStatus, Invoice::query()->find($invoiceId)->status);
        $this->assertSame($paymentStatus, Payment::query()->find($paymentId)->status);
        $this->assertSame($shippedQty, (int) ShipmentItem::query()->find($itemId)->quantity);
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function confirmedDeliveryContext(string $title = 'RMA RFQ'): array
    {
        [$buyer, $supplier, $shipmentId, $poId, $itemId] = $this->deliveredWithoutConfirmation($title);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated();

        return [$buyer, $supplier, $shipmentId, $poId, $itemId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function deliveredWithoutConfirmation(string $title = 'RMA RFQ'): array
    {
        [$buyer, $supplier, $shipmentId, $poId] = $this->pendingShipmentContext($title);
        $itemId = (int) ShipmentItem::query()->where('shipment_id', $shipmentId)->value('id');

        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/processing')->assertOk();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/shipments/'.$shipmentId.'/ship')->assertOk();
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        return [$buyer, $supplier, $shipmentId, $poId, $itemId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function pendingShipmentContext(string $title = 'RMA RFQ'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext($title);

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->json('shipment.id');

        return [$buyer, $supplier, $shipmentId, $poId];
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'RMA RFQ'): array
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
