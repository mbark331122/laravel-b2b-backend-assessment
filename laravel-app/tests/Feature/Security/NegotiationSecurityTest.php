<?php

namespace Tests\Feature\Security;

use App\Models\Negotiation;
use App\Models\NegotiationOffer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use App\Models\User;

class NegotiationSecurityTest extends SecurityTestCase
{
    public function test_cross_company_negotiation_isolation(): void
    {
        [$rfqId, $quotationId, $negotiationId, $rfqItemId] = $this->openNegotiationContext();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/negotiations')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/negotiations/'.$negotiationId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/negotiations/'.$negotiationId)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/negotiations')
            ->assertOk()
            ->assertJsonCount(0, 'negotiations');

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation', [
                'supplier_company_id' => $this->supplierUser()->company_id,
            ])
            ->assertNotFound();
    }

    public function test_turn_enforcement_and_offer_immutability(): void
    {
        [$rfqId, $quotationId, $negotiationId, $rfqItemId] = $this->openNegotiationContext();

        $offer1 = NegotiationOffer::query()->where('negotiation_id', $negotiationId)->firstOrFail();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 1,
                    'unit_price' => 1,
                ]],
            ])
            ->assertUnprocessable();

        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/negotiations/'.$negotiationId.'/offers/'.$offer1->id, [
                'total' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->deleteJson('/api/negotiations/'.$negotiationId.'/offers/'.$offer1->id)
            ->assertNotFound();

        $this->assertSame('proposed', $offer1->fresh()->status);
        $this->assertSame(1, $offer1->fresh()->sequence);
    }

    public function test_acceptance_rules_and_ownership_spoofing_ignored(): void
    {
        [$rfqId, $quotationId, $negotiationId, $rfqItemId] = $this->openNegotiationContext();

        $buyerOfferId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'company_id' => $this->supplierUser()->company_id,
                'side' => 'supplier',
                'buyer_id' => 1,
                'items' => [[
                    'rfq_item_id' => $rfqItemId,
                    'quantity' => 5,
                    'unit_price' => 20,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('offer.side', NegotiationOffer::SIDE_BUYER)
            ->json('offer.id');

        $firstOfferId = NegotiationOffer::query()
            ->where('negotiation_id', $negotiationId)
            ->where('sequence', 1)
            ->value('id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$firstOfferId.'/accept')
            ->assertUnprocessable();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$buyerOfferId.'/accept')
            ->assertUnprocessable();

        NegotiationOffer::query()->whereKey($buyerOfferId)->update([
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$buyerOfferId.'/accept')
            ->assertUnprocessable();

        NegotiationOffer::query()->whereKey($buyerOfferId)->update([
            'valid_until' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers/'.$buyerOfferId.'/accept')
            ->assertOk()
            ->assertJsonPath('negotiation.status', Negotiation::STATUS_ACCEPTED);
    }

    public function test_missing_permissions_forbidden(): void
    {
        [$rfqId, $quotationId, $negotiationId] = $this->openNegotiationContext();

        $limitedBuyer = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.neg.buyer@example.com',
            [Permission::RFQ_READ, Permission::QUOTATION_READ]
        );

        $this->actingAs($limitedBuyer, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/negotiations')
            ->assertForbidden();

        $this->actingAs($limitedBuyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertForbidden();

        $limitedSupplier = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.neg.supplier@example.com',
            [Permission::SUPPLIER_RFQ_READ, Permission::QUOTATION_READ]
        );

        $this->actingAs($limitedSupplier, 'sanctum')
            ->getJson('/api/supplier/negotiations')
            ->assertForbidden();
    }

    public function test_foreign_rfq_item_rejected_in_counter_offer(): void
    {
        [$rfqId, $quotationId, $negotiationId, $rfqItemId] = $this->openNegotiationContext();

        $otherRfqId = $this->createSubmittedRfq(
            $this->userB(),
            Product::query()->where('sku', 'SUG-45')->firstOrFail()
        );
        $foreignItemId = Rfq::query()->findOrFail($otherRfqId)->items()->value('id');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'items' => [[
                    'rfq_item_id' => $foreignItemId,
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfq_item_id']);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function openNegotiationContext(): array
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

        $quotationId = $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/supplier/rfq-distributions/'.$distributionId.'/quotation', [
                'currency' => 'USD',
                'valid_until' => now()->addDays(7)->toDateString(),
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

        $negotiationId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');

        return [$rfqId, $quotationId, $negotiationId, $rfqItemId];
    }

    private function createSubmittedRfq(User $buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Secure Neg RFQ',
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
