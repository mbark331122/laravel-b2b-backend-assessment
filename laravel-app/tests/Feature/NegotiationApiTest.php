<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Negotiation;
use App\Models\NegotiationOffer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NegotiationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_opens_negotiation_with_immutable_initial_offer_from_quotation(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext();

        $negotiation = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation', [
                'company_id' => 999,
                'buyer_company_id' => 999,
                'supplier_company_id' => 999,
                'tenant_id' => 999,
                'status' => Negotiation::STATUS_ACCEPTED,
            ])
            ->assertCreated()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_OPEN)
            ->assertJsonPath('negotiation.quotation_id', $quotationId)
            ->assertJsonPath('negotiation.rfq_id', $rfqId)
            ->assertJsonPath('negotiation.next_turn_side', NegotiationOffer::SIDE_BUYER)
            ->assertJsonPath('negotiation.offers.0.side', NegotiationOffer::SIDE_SUPPLIER)
            ->assertJsonPath('negotiation.offers.0.sequence', 1)
            ->assertJsonPath('negotiation.offers.0.status', NegotiationOffer::STATUS_PROPOSED)
            ->json('negotiation');

        $this->assertSame($buyer->company_id, $negotiation['buyer_company']['id']);
        $this->assertSame($supplier->company_id, $negotiation['supplier_company']['id']);
        $this->assertSame('1125.00', $negotiation['offers'][0]['total']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::NEGOTIATION_CREATED,
            'company_id' => $buyer->company_id,
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertUnprocessable();
    }

    public function test_alternating_counter_offers_accept_and_block_further_offers(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext();

        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'subtotal' => 1,
                'total' => 1,
                'side' => 'supplier',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 8,
                    'unit_price' => 90,
                    'line_total' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('offer.side', NegotiationOffer::SIDE_BUYER)
            ->assertJsonPath('offer.sequence', 2)
            ->assertJsonPath('offer.subtotal', '720.00')
            ->assertJsonPath('offer.total', '735.00')
            ->assertJsonPath('negotiation.next_turn_side', NegotiationOffer::SIDE_SUPPLIER);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 8,
                    'unit_price' => 85,
                ]],
            ])
            ->assertUnprocessable();

        $supplierOfferId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 8,
                    'unit_price' => 88,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('offer.side', NegotiationOffer::SIDE_SUPPLIER)
            ->assertJsonPath('offer.sequence', 3)
            ->json('offer.id');

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$supplierOfferId.'/accept')
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$supplierOfferId.'/accept')
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_ACCEPTED)
            ->assertJsonPath('negotiation.accepted_offer_id', $supplierOfferId);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::NEGOTIATION_OFFER_ACCEPTED,
            'company_id' => $buyer->company_id,
        ]);

        $this->assertSame(Quotation::STATUS_SUBMITTED, Quotation::query()->find($quotationId)->status);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 1,
                ]],
            ])
            ->assertUnprocessable();

        $offers = $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/negotiations/'.$negotiationId.'/offers')
            ->assertOk()
            ->assertJsonCount(3, 'offers')
            ->json('offers');

        $this->assertSame(1, $offers[0]['sequence']);
        $this->assertSame(NegotiationOffer::STATUS_SUPERSEDED, $offers[0]['status']);
        $this->assertSame(NegotiationOffer::STATUS_SUPERSEDED, $offers[1]['status']);
        $this->assertSame(NegotiationOffer::STATUS_ACCEPTED, $offers[2]['status']);
    }

    public function test_reject_and_withdraw_terminate_negotiation(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext();

        $negReject = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negReject.'/reject', ['reason' => 'no fit'])
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_REJECTED);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negReject.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 1,
                ]],
            ])
            ->assertUnprocessable();

        // New quotation/distribution path for withdraw case: reopen after withdraw of quote not needed —
        // create second RFQ+quote for withdraw test.
        [$buyer2, $supplier2, $rfqId2, $quotationId2, $rfqItemId2] = $this->submittedQuoteContext(title: 'Second');

        $negWithdraw = $this->actingAs($buyer2, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId2.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        $this->actingAs($supplier2, 'sanctum')
            ->postJson('/api/negotiations/'.$negWithdraw.'/withdraw')
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_WITHDRAWN);
    }

    public function test_expiration_blocks_new_offers_but_history_remains_readable(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext();

        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation', [
                'valid_until' => now()->addDays(5)->toDateString(),
            ])
            ->assertCreated()
            ->json('negotiation.id');

        Negotiation::query()->whereKey($negotiationId)->update([
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/negotiations/'.$negotiationId)
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_EXPIRED)
            ->assertJsonCount(1, 'negotiation.offers');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 1,
                ]],
            ])
            ->assertUnprocessable();
    }

    public function test_buyer_and_supplier_list_endpoints(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId] = $this->submittedQuoteContext();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/negotiations')
            ->assertOk()
            ->assertJsonCount(1, 'negotiations');

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/supplier/negotiations')
            ->assertOk()
            ->assertJsonCount(1, 'negotiations');
    }

    public function test_draft_withdrawn_expired_quotations_cannot_open_negotiation(): void
    {
        [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId] = $this->submittedQuoteContext();

        Quotation::query()->whereKey($quotationId)->update(['status' => Quotation::STATUS_DRAFT, 'active_lock' => null]);
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertUnprocessable();

        Quotation::query()->whereKey($quotationId)->update(['status' => Quotation::STATUS_WITHDRAWN]);
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertUnprocessable();

        Quotation::query()->whereKey($quotationId)->update(['status' => Quotation::STATUS_EXPIRED]);
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int}
     */
    private function submittedQuoteContext(string $title = 'Neg RFQ'): array
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

        return [$buyer, $supplier, $rfqId, $quotationId, $rfqItemId];
    }
}
