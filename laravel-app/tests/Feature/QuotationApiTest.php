<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_supplier_creates_draft_quotation_with_server_ownership_and_totals(): void
    {
        [$buyer, $supplier, $distributionId, $rfqItemId] = $this->distributedSugarContext();

        $quotation = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'usd',
                'valid_until' => now()->addDays(10)->toDateString(),
                'notes' => 'FOB terms',
                'shipping_amount' => 50,
                'tax_amount' => 25,
                'company_id' => 999,
                'supplier_company_id' => 999,
                'tenant_id' => 999,
                'status' => Quotation::STATUS_SUBMITTED,
                'subtotal' => 1,
                'total' => 1,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 100,
                    'unit_price' => 10.5,
                    'line_total' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('quotation.status', Quotation::STATUS_DRAFT)
            ->assertJsonPath('quotation.supplier_company_id', $supplier->company_id)
            ->assertJsonPath('quotation.currency', 'USD')
            ->assertJsonPath('quotation.subtotal', '1050.00')
            ->assertJsonPath('quotation.shipping_amount', '50.00')
            ->assertJsonPath('quotation.tax_amount', '25.00')
            ->assertJsonPath('quotation.total', '1125.00')
            ->assertJsonPath('quotation.items.0.line_total', '1050.00')
            ->json('quotation');

        $this->assertSame($supplier->company_id, $quotation['supplier_company_id']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::QUOTATION_CREATED,
            'company_id' => $supplier->company_id,
        ]);
    }

    public function test_draft_item_management_and_submit_withdraw_flow(): void
    {
        [$buyer, $supplier, $distributionId, $rfqItemId] = $this->distributedSugarContext();

        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(5)->toDateString(),
            ])
            ->assertCreated()
            ->json('quotation.id');

        $itemId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/items', [
                'rfq_item_id' => $rfqItemId,
                'quantity' => 10,
                'unit_price' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('quotation.subtotal', '1000.00')
            ->json('quotation_item.id');

        $this->actingAs($supplier, 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId.'/items/'.$itemId, [
                'quantity' => 20,
                'unit_price' => 50,
                'line_total' => 9999,
            ])
            ->assertOk()
            ->assertJsonPath('quotation_item.line_total', '1000.00')
            ->assertJsonPath('quotation.total', '1000.00');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk()
            ->assertJsonPath('quotation.status', Quotation::STATUS_SUBMITTED);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::QUOTATION_SUBMITTED,
            'company_id' => $supplier->company_id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId, [
                'notes' => 'hack',
            ])
            ->assertUnprocessable();

        $this->actingAs($supplier, 'sanctum')
            ->deleteJson('/api/supplier/quotations/'.$quotationId)
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.Quotation::query()->find($quotationId)->rfq_id.'/quotations')
            ->assertOk()
            ->assertJsonCount(1, 'quotations')
            ->assertJsonPath('quotations.0.status', Quotation::STATUS_SUBMITTED);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw', ['reason' => 'reprice'])
            ->assertOk()
            ->assertJsonPath('quotation.status', Quotation::STATUS_WITHDRAWN);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::QUOTATION_WITHDRAWN,
            'company_id' => $supplier->company_id,
        ]);
    }

    public function test_buyer_comparison_is_neutral_and_deterministic(): void
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplierA = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $supplierB = User::query()->where('email', UserSeeder::SUPPLIER_USER_B_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();

        // Give supplier B a published sugar product so matching includes both.
        $profileB = SupplierProfile::query()->where('display_name', 'Supplier Company B Trading')->firstOrFail();
        $sugarProductB = $product->replicate();
        $sugarProductB->company_id = $supplierB->company_id;
        $sugarProductB->supplier_profile_id = $profileB->id;
        $sugarProductB->sku = 'SUG-45-B';
        $sugarProductB->name = 'ICUMSA 45 Sugar B';
        $sugarProductB->status = Product::STATUS_PUBLISHED;
        $sugarProductB->save();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id, $profileB->id],
            ])
            ->assertCreated();

        $distA = RfqDistribution::query()->where('rfq_id', $rfqId)->where('supplier_company_id', $supplierA->company_id)->firstOrFail();
        $distB = RfqDistribution::query()->where('rfq_id', $rfqId)->where('supplier_company_id', $supplierB->company_id)->firstOrFail();
        $rfqItemId = Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        $this->submitFullQuote($supplierA, $distA->id, $rfqItemId, 40);
        $this->submitFullQuote($supplierB, $distB->id, $rfqItemId, 35);

        $compare = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations/compare')
            ->assertOk()
            ->assertJsonCount(2, 'comparison')
            ->json('comparison');

        $this->assertFalse(collect($compare)->contains(fn ($row) => array_key_exists('score', $row)));
        $this->assertFalse(collect($compare)->contains(fn ($row) => array_key_exists('rank', $row)));
        $this->assertFalse(collect($compare)->contains(fn ($row) => array_key_exists('winner', $row)));
        $this->assertFalse(collect($compare)->contains(fn ($row) => array_key_exists('best', $row)));

        $names = collect($compare)->pluck('supplier.display_name')->all();
        $sorted = collect($names)->sort()->values()->all();
        $this->assertSame($sorted, $names);
    }

    public function test_expiration_is_evaluated_without_scheduler(): void
    {
        [$buyer, $supplier, $distributionId, $rfqItemId] = $this->distributedSugarContext();

        $quotationId = $this->submitFullQuote($supplier, $distributionId, $rfqItemId, 10);

        Quotation::query()->whereKey($quotationId)->update([
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.Quotation::query()->find($quotationId)->rfq_id.'/quotations/'.$quotationId)
            ->assertOk()
            ->assertJsonPath('quotation.status', Quotation::STATUS_EXPIRED);

        $this->assertFalse(Quotation::query()->find($quotationId)->isActiveOffer());
    }

    public function test_cannot_create_on_withdrawn_distribution_and_can_recreate_after_withdraw(): void
    {
        [$buyer, $supplier, $distributionId, $rfqItemId] = $this->distributedSugarContext();
        $rfqId = RfqDistribution::query()->findOrFail($distributionId)->rfq_id;

        $quotationId = $this->submitFullQuote($supplier, $distributionId, $rfqItemId, 12);

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 5,
                    'unit_price' => 9,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('quotation.status', Quotation::STATUS_DRAFT);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw')
            ->assertOk();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
            ])
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function distributedSugarContext(): array
    {
        $buyer = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $supplier = User::query()->where('email', UserSeeder::SUPPLIER_USER_EMAIL)->firstOrFail();
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $rfqId = $this->createSubmittedRfqWithProduct($buyer, $product);

        $distributionId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->assertCreated()
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        return [$buyer, $supplier, $distributionId, $rfqItemId];
    }

    private function createSubmittedRfqWithProduct(User $buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Quote RFQ',
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

        // Remove legacy auto item if present so quotation requires only product item.
        $rfq = Rfq::query()->findOrFail($rfqId);
        $rfq->items()->whereNull('product_id')->delete();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk();

        return $rfqId;
    }

    private function submitFullQuote(User $supplier, int $distributionId, int $rfqItemId, float $unitPrice): int
    {
        $quotationId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(7)->toDateString(),
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => $unitPrice,
                ]],
            ])
            ->assertCreated()
            ->json('quotation.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        return $quotationId;
    }
}
