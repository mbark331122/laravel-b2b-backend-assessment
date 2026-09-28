<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeliveryConfirmation;
use App\Models\Negotiation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Shipment;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_confirms_delivered_shipment_with_server_number_and_idempotency(): void
    {
        [$buyer, $supplier, $shipmentId, $poId] = $this->deliveredShipmentContext();

        $confirmation = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation', [
                'notes' => 'Received in good condition',
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'shipment_id' => 999,
                'purchase_order_id' => 999,
                'confirmed_by_user_id' => 999,
                'number' => 'CLIENT-DEL',
                'status' => 'rejected',
                'confirmed_at' => '2000-01-01T00:00:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('delivery_confirmation.status', DeliveryConfirmation::STATUS_CONFIRMED)
            ->assertJsonPath('delivery_confirmation.shipment_id', $shipmentId)
            ->assertJsonPath('delivery_confirmation.purchase_order_id', $poId)
            ->assertJsonPath('delivery_confirmation.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('delivery_confirmation.supplier_company.id', $supplier->company_id)
            ->assertJsonPath('delivery_confirmation.confirmed_by.id', $buyer->id)
            ->assertJsonPath('delivery_confirmation.notes', 'Received in good condition')
            ->json('delivery_confirmation');

        $this->assertMatchesRegularExpression('/^DEL-\d{4}-\d{6}$/', $confirmation['number']);
        $this->assertNotSame('CLIENT-DEL', $confirmation['number']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $confirmation['confirmed_at']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::DELIVERY_CONFIRMATION_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertOk()
            ->assertJsonPath('delivery_confirmation.id', $confirmation['id']);

        $this->assertDatabaseCount('delivery_confirmations', 1);

        $this->assertSame(
            PurchaseOrder::STATUS_CONFIRMED,
            PurchaseOrder::query()->find($poId)->status
        );

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertOk()
            ->assertJsonPath('delivery_confirmation.id', $confirmation['id']);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/delivery-confirmations')
            ->assertOk()
            ->assertJsonCount(1, 'delivery_confirmations');
    }

    public function test_non_delivered_shipment_cannot_be_confirmed(): void
    {
        [$buyer, $supplier, $shipmentId] = $this->pendingShipmentContext();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/processing')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/ship')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertUnprocessable();

        [$buyer2, $supplier2, $cancelId] = $this->pendingShipmentContext(title: 'Cancel Del');
        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/shipments/'.$cancelId.'/cancel')
            ->assertOk();

        $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/shipments/'.$cancelId.'/delivery-confirmation')
            ->assertUnprocessable();
    }

    public function test_confirmation_independent_of_payment_and_lists(): void
    {
        [$buyer, $supplier, $shipmentId, $poId] = $this->deliveredShipmentContext(title: 'Pay Indep');

        $invoiceId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/invoice')
            ->assertCreated()
            ->json('invoice.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/issue')
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/invoices/'.$invoiceId.'/payment', [
                'method' => Payment::METHOD_BANK_TRANSFER,
            ])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/delivery-confirmation')
            ->assertCreated()
            ->assertJsonPath('delivery_confirmation.status', DeliveryConfirmation::STATUS_CONFIRMED);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/delivery-confirmations')
            ->assertOk()
            ->assertJsonCount(1, 'delivery_confirmations');
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function deliveredShipmentContext(string $title = 'Del RFQ'): array
    {
        [$buyer, $supplier, $shipmentId, $poId] = $this->pendingShipmentContext($title);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/processing')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/ship')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        return [$buyer, $supplier, $shipmentId, $poId];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3?: int}
     */
    private function pendingShipmentContext(string $title = 'Del RFQ'): array
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
    private function confirmedPoContext(string $title = 'Del RFQ'): array
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
