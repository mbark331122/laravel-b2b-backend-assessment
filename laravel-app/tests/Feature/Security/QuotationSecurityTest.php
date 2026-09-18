<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\UserSeeder;

class QuotationSecurityTest extends SecurityTestCase
{
    public function test_supplier_cannot_access_or_mutate_another_suppliers_quotation(): void
    {
        [$rfqId, $distributionId, $rfqItemId, $quotationId] = $this->buyerDistributedSubmittedQuote();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/quotations/'.$quotationId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId, ['notes' => 'x'])
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->deleteJson('/api/supplier/quotations/'.$quotationId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw')
            ->assertNotFound();
    }

    public function test_supplier_cannot_quote_foreign_withdrawn_or_undistributed(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/999999/quotation', ['currency' => 'USD'])
            ->assertNotFound();

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->json('distributions.0.id');

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'supplier_company_id' => $this->supplierUser()->company_id,
                'company_id' => $this->supplierUser()->company_id,
            ])
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw')
            ->assertOk();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
            ])
            ->assertUnprocessable();
    }

    public function test_buyer_isolation_and_no_mutation(): void
    {
        [$rfqId, $distributionId, $rfqItemId, $quotationId] = $this->buyerDistributedSubmittedQuote();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations/compare')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations/'.$quotationId)
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId, ['notes' => 'buyer'])
            ->assertForbidden();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw')
            ->assertForbidden();
    }

    public function test_pricing_integrity_ignores_client_totals_and_rejects_invalid_prices(): void
    {
        [$rfqId, $distributionId, $rfqItemId] = $this->buyerDistributedDraftReady();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 0,
                    'unit_price' => 10,
                ]],
            ])
            ->assertUnprocessable();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 5,
                    'unit_price' => -1,
                ]],
            ])
            ->assertUnprocessable();

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'subtotal' => 1,
                'total' => 1,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 4,
                    'unit_price' => 2.5,
                    'line_total' => 999,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('quotation.subtotal', '10.00')
            ->assertJsonPath('quotation.total', '25.00')
            ->assertJsonPath('quotation.items.0.line_total', '10.00')
            ->json('quotation.id');

        $otherRfqId = $this->createSubmittedRfq($this->userB(), Product::query()->where('sku', 'SUG-45')->firstOrFail());
        $foreignItemId = Rfq::query()->findOrFail($otherRfqId)->items()->value('id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/items', [
                'rfq_item_id' => $foreignItemId,
                'quantity' => 1,
                'unit_price' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfq_item_id']);
    }

    public function test_status_injection_and_lifecycle_abuse_blocked(): void
    {
        [$rfqId, $distributionId, $rfqItemId] = $this->buyerDistributedDraftReady();

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'status' => Quotation::STATUS_SUBMITTED,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('quotation.status', Quotation::STATUS_DRAFT)
            ->json('quotation.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertUnprocessable();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId, [
                'status' => Quotation::STATUS_DRAFT,
                'notes' => 'nope',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw')
            ->assertOk();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/withdraw')
            ->assertUnprocessable();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->patchJson('/api/supplier/quotations/'.$quotationId, ['notes' => 'x'])
            ->assertUnprocessable();
    }

    public function test_missing_quotation_permissions_forbidden(): void
    {
        [$rfqId, $distributionId, $rfqItemId, $quotationId] = $this->buyerDistributedSubmittedQuote();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.quote@example.com',
            [Permission::SUPPLIER_RFQ_READ, Permission::PRODUCT_READ]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->getJson('/api/supplier/quotations/'.$quotationId)
            ->assertForbidden();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.compare@example.com',
            [Permission::RFQ_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations/compare')
            ->assertForbidden();
    }

    public function test_draft_quotations_are_hidden_from_buyers(): void
    {
        [$rfqId, $distributionId, $rfqItemId] = $this->buyerDistributedDraftReady();

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ])
            ->json('quotation.id');

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations')
            ->assertOk()
            ->assertJsonCount(0, 'quotations');

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/quotations/'.$quotationId)
            ->assertNotFound();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function buyerDistributedDraftReady(): array
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);
        $profile = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profile->id],
            ])
            ->json('distributions.0.id');

        $rfqItemId = (int) Rfq::query()->findOrFail($rfqId)->items()->whereNotNull('product_id')->value('id');

        return [$rfqId, $distributionId, $rfqItemId];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function buyerDistributedSubmittedQuote(): array
    {
        [$rfqId, $distributionId, $rfqItemId] = $this->buyerDistributedDraftReady();

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(5)->toDateString(),
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 10,
                    'unit_price' => 12,
                ]],
            ])
            ->assertCreated()
            ->json('quotation.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/quotations/'.$quotationId.'/submit')
            ->assertOk();

        return [$rfqId, $distributionId, $rfqItemId, $quotationId];
    }

    private function createSubmittedRfq(User $buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Secure Quote RFQ',
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

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk();

        return $rfqId;
    }
}
