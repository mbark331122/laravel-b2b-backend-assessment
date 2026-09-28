<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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

class ShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_supplier_creates_shipment_from_confirmed_po_with_snapshots_and_number(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $shipment = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment', [
                'carrier' => 'Manual Carrier',
                'tracking_number' => 'TRK-001',
                'shipping_method' => 'sea_freight',
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'number' => 'CLIENT-SHP',
                'status' => Shipment::STATUS_SHIPPED,
                'items' => [['quantity' => 1, 'unit_price' => 1]],
                'destination_address' => [
                    'city' => 'Jeddah',
                    'country' => 'SA',
                    'address_line' => 'Port Gate 3',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('shipment.status', Shipment::STATUS_PENDING)
            ->assertJsonPath('shipment.carrier', 'Manual Carrier')
            ->assertJsonPath('shipment.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('shipment.supplier_company.id', $supplier->company_id)
            ->json('shipment');

        $this->assertMatchesRegularExpression('/^SHP-\d{4}-\d{6}$/', $shipment['number']);
        $this->assertNotSame('CLIENT-SHP', $shipment['number']);
        $this->assertCount(1, $shipment['items']);
        $this->assertSame(8, $shipment['items'][0]['quantity']);
        $this->assertSame('720.00', $shipment['items'][0]['line_total']);
        $this->assertSame('Jeddah', $shipment['destination_address_snapshot']['city']);
        $this->assertSame('SA', $shipment['destination_address_snapshot']['country']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::SHIPMENT_CREATED,
            'company_id' => $supplier->company_id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertOk()
            ->assertJsonPath('shipment.id', $shipment['id']);

        $this->assertDatabaseCount('shipments', 1);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertOk()
            ->assertJsonPath('shipment.id', $shipment['id']);
    }

    public function test_shipment_lifecycle_and_operational_update_immutability(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $shipmentId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment', [
                'carrier' => 'Carrier A',
            ])
            ->assertCreated()
            ->json('shipment.id');

        $beforeItems = Shipment::query()->with('items')->findOrFail($shipmentId)->items->first();

        Product::query()->where('sku', 'SUG-45')->update([
            'name' => 'Mutated Product',
            'wholesale_price' => '1.00',
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->patchJson('/api/shipments/'.$shipmentId, [
                'carrier' => 'Carrier B',
                'tracking_number' => 'TRK-99',
                'number' => 'HACK',
                'status' => Shipment::STATUS_DELIVERED,
                'buyer_company_id' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('shipment.carrier', 'Carrier B')
            ->assertJsonPath('shipment.tracking_number', 'TRK-99')
            ->assertJsonPath('shipment.status', Shipment::STATUS_PENDING)
            ->assertJsonPath('shipment.number', Shipment::query()->find($shipmentId)->number);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/processing')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_PROCESSING);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/ship')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_SHIPPED);

        $this->actingAs($supplier, 'sanctum')
            ->patchJson('/api/shipments/'.$shipmentId, [
                'carrier' => 'Carrier C',
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/deliver')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);

        $after = Shipment::query()->with('items')->findOrFail($shipmentId);
        $this->assertSame($beforeItems->description, $after->items->first()->description);
        $this->assertSame((string) $beforeItems->line_total, (string) $after->items->first()->line_total);
        $this->assertNotSame('Mutated Product', $after->items->first()->description);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/shipments/'.$shipmentId.'/cancel')
            ->assertUnprocessable();

        [$buyer2, $supplier2, $poId2] = $this->confirmedPoContext(title: 'Cancel Ship');
        $cancelId = $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId2.'/shipment')
            ->json('shipment.id');

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/shipments/'.$cancelId.'/cancel')
            ->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_CANCELLED);

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/shipments/'.$cancelId.'/processing')
            ->assertUnprocessable();
    }

    public function test_non_confirmed_po_cannot_create_shipment_and_payment_independent(): void
    {
        [$buyer, $supplier, $poId] = $this->draftPoContext();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/submit')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/purchase-orders/'.$poId.'/confirm')
            ->assertOk();

        // Payment state does not block shipment creation.
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

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated()
            ->assertJsonPath('shipment.status', Shipment::STATUS_PENDING);
    }

    public function test_buyer_and_supplier_list_endpoints(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoContext();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/shipment')
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/shipments')
            ->assertOk()
            ->assertJsonCount(1, 'shipments');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/shipments')
            ->assertOk()
            ->assertJsonCount(1, 'shipments');
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoContext(string $title = 'Ship RFQ'): array
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
    private function draftPoContext(string $title = 'Ship RFQ'): array
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
