<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\SecurityTestCase;

class Sprint17FoundationTest extends SecurityTestCase
{
    public function test_optional_pagination_preserves_unbounded_contract_by_default(): void
    {
        $buyer = $this->userA();

        $unbounded = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('meta')
            ->json('products');

        $this->assertIsArray($unbounded);
        $this->assertNotEmpty($unbounded);

        $paginated = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/products?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->json();

        $this->assertCount(1, $paginated['products']);
        $this->assertArrayHasKey('total', $paginated['meta']);
        $this->assertArrayHasKey('last_page', $paginated['meta']);
    }

    public function test_purchase_order_and_invoice_indexes_support_optional_pagination(): void
    {
        $buyer = $this->userA();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/purchase-orders?per_page=5')
            ->assertOk()
            ->assertJsonStructure([
                'purchase_orders',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/invoices?per_page=5')
            ->assertOk()
            ->assertJsonStructure([
                'invoices',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_quotation_expiration_is_audited(): void
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Sprint17 Expire Audit',
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
                'valid_until' => now()->addDay()->toDateString(),
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 10, 'unit_price' => 1]],
            ])
            ->assertCreated()
            ->json('quotation.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        Carbon::setTestNow(now()->addDays(3));

        $quotation = Quotation::query()->findOrFail($quotationId);
        $this->assertTrue($quotation->refreshExpiration());
        $this->assertSame(Quotation::STATUS_EXPIRED, $quotation->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::QUOTATION_EXPIRED,
            'auditable_id' => $quotationId,
        ]);

        Carbon::setTestNow();
    }

    public function test_duplicate_active_quotation_still_rejected_under_locked_create(): void
    {
        $buyer = $this->userA();
        $supplier = $this->supplierUser();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Sprint17 Quotation Lock',
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

        $payload = [
            'currency' => 'USD',
            'valid_until' => now()->addDays(5)->toDateString(),
            'shipping_amount' => 10,
            'tax_amount' => 5,
            'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 10, 'unit_price' => 1]],
        ];

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', $payload)
            ->assertCreated();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quotation']);
    }
}
