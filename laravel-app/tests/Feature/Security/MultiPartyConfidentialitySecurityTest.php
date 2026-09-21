<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\IntermediaryCommission;
use App\Models\Negotiation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderParty;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Services\TransactionVisibilityService;
use Database\Seeders\UserSeeder;

class MultiPartyConfidentialitySecurityTest extends SecurityTestCase
{
    public function test_buyer_cannot_identify_supplier_or_see_commissions_when_hidden(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $poShow = $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'])
            ->assertOk()
            ->json('purchase_order');

        $this->assertNull($poShow['supplier_company']);
        $this->assertNotNull($poShow['buyer_company']);

        $parties = $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties')
            ->assertOk()
            ->json('parties');

        $byRole = collect($parties)->keyBy('role');
        $this->assertNull($byRole['supplier']['company']);

        // Collect intermediaries from list
        $intermediaries = collect($parties)->where('role', PurchaseOrderParty::ROLE_INTERMEDIARY)->values();
        $this->assertCount(2, $intermediaries);
        foreach ($intermediaries as $party) {
            $this->assertNull($party['company']);
        }

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/'.$ctx['supplier_party_id'])
            ->assertOk()
            ->assertJsonPath('party.company', null);

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/commissions')
            ->assertForbidden();

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_1_id'])
            ->assertNotFound();

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/search?q='.urlencode($ctx['supplier_company_name']))
            ->assertOk()
            ->assertJsonCount(0, 'parties');

        $export = $this->actingAs($ctx['buyer'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/export-view')
            ->assertOk()
            ->json();

        $this->assertNull($export['purchase_order']['supplier_company']);
        $this->assertSame([], $export['commissions']);
        $this->assertSame([], $export['confidential_notes']);
    }

    public function test_supplier_cannot_identify_buyer_or_intermediaries_when_hidden(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $poShow = $this->actingAs($ctx['supplier'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'])
            ->assertOk()
            ->json('purchase_order');

        $this->assertNull($poShow['buyer_company']);
        $this->assertNotNull($poShow['supplier_company']);

        $this->actingAs($ctx['supplier'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/search?q='.urlencode('Company A'))
            ->assertOk()
            ->assertJsonCount(0, 'parties');

        $this->actingAs($ctx['supplier'], 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_2_id'])
            ->assertNotFound();
    }

    public function test_intermediaries_cannot_see_each_others_identity_or_commission(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $i1Parties = $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties')
            ->assertOk()
            ->json('parties');

        foreach ($i1Parties as $party) {
            if ((int) $party['id'] === (int) $ctx['intermediary1_party_id']) {
                $this->assertNotNull($party['company']);
                $this->assertSame($ctx['intermediary1_company_id'], $party['company']['id']);
            } else {
                $this->assertNull($party['company']);
            }
        }

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/commissions')
            ->assertOk()
            ->assertJsonCount(1, 'commissions')
            ->assertJsonPath('commissions.0.id', $ctx['commission_1_id']);

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_2_id'])
            ->assertNotFound();

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/'.$ctx['intermediary2_party_id'])
            ->assertOk()
            ->assertJsonPath('party.company', null);

        $this->actingAs($ctx['intermediary2'], 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_1_id'])
            ->assertNotFound();

        $this->actingAs($ctx['intermediary2'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/search?q='.urlencode($ctx['intermediary1_company_name']))
            ->assertOk()
            ->assertJsonCount(0, 'parties');
    }

    public function test_internal_user_least_privilege_and_audited_access(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $placeholderCompany = new Company;
        $placeholderCompany->name = 'Internal Low Placeholder';
        $placeholderCompany->is_buyer = false;
        $placeholderCompany->is_supplier = false;
        $placeholderCompany->save();

        $lowInternal = $this->userWithoutPermissions(
            $placeholderCompany,
            'internal.low@example.com',
            [Permission::PURCHASE_ORDER_READ, Permission::PURCHASE_ORDER_PARTY_READ]
        );
        // Platform-internal least-privilege actor: no company membership, no commission/confidential rights.
        $lowInternal->company_id = null;
        $lowInternal->save();

        // Without admin and without company/party membership, PO is not found.
        $this->actingAs($lowInternal, 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'])
            ->assertNotFound();

        $privileged = $this->admin();
        $this->actingAs($privileged, 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_1_id'])
            ->assertOk()
            ->assertJsonPath('commission.amount', '100.00');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::COMMISSION_VIEWED,
            'auditable_id' => $ctx['commission_1_id'],
        ]);

        $this->actingAs($privileged, 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/confidential-notes')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::CONFIDENTIAL_VIEWED,
        ]);
    }

    public function test_spoofing_idor_and_mass_assignment_blocked(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->postJson('/api/purchase-orders/'.$ctx['po_id'].'/parties', [
                'company_id' => $ctx['intermediary2_company_id'],
                'role' => 'supplier',
                'visibility' => 'public',
                'commission' => 999999,
                'is_internal' => true,
            ])
            ->assertForbidden();

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->postJson('/api/purchase-orders/'.$ctx['po_id'].'/identity-grants', [
                'viewer_party_id' => $ctx['intermediary1_party_id'],
                'visible_party_id' => $ctx['intermediary2_party_id'],
                'visibility' => 'public',
                'is_internal' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::PARTY_IDENTITY_GRANTED,
        ]);

        // After grant, I1 can see I2 identity but still not I2 commission.
        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/'.$ctx['intermediary2_party_id'])
            ->assertOk()
            ->assertJsonPath('party.company.id', $ctx['intermediary2_company_id']);

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/commissions/'.$ctx['commission_2_id'])
            ->assertNotFound();

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties?include=commissions&include=confidential')
            ->assertOk();

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.($ctx['po_id'] + 9999).'/parties')
            ->assertNotFound();

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/parties/'.($ctx['intermediary2_party_id'] + 9999))
            ->assertNotFound();
    }

    public function test_n_intermediaries_supported_without_schema_change(): void
    {
        $ctx = $this->confidentialMultiPartyContext();

        $company3 = $this->createIntermediaryCompany('Intermediary Three Trading');
        $user3 = $this->createIntermediaryUser($company3, 'intermediary3@example.com');

        $party3 = $this->actingAs($ctx['buyer'], 'sanctum')
            ->postJson('/api/purchase-orders/'.$ctx['po_id'].'/parties', [
                'company_id' => $company3->id,
                'sequence' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('party.role', PurchaseOrderParty::ROLE_INTERMEDIARY)
            ->json('party');

        $this->actingAs($ctx['buyer'], 'sanctum')
            ->postJson('/api/purchase-orders/'.$ctx['po_id'].'/commissions', [
                'purchase_order_party_id' => $party3['id'],
                'amount' => 55.25,
                'currency' => 'USD',
            ])
            ->assertCreated();

        $this->actingAs($user3, 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/commissions')
            ->assertOk()
            ->assertJsonCount(1, 'commissions')
            ->assertJsonPath('commissions.0.amount', '55.25');

        $this->actingAs($ctx['intermediary1'], 'sanctum')
            ->getJson('/api/purchase-orders/'.$ctx['po_id'].'/commissions')
            ->assertOk()
            ->assertJsonCount(1, 'commissions')
            ->assertJsonPath('commissions.0.id', $ctx['commission_1_id']);
    }

    public function test_classic_direct_trade_still_exposes_mutual_identities(): void
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly('Classic Mutual');

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/purchase-orders/'.$poId)
            ->assertOk()
            ->assertJsonPath('purchase_order.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('purchase_order.supplier_company.id', $supplier->company_id);

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/purchase-orders/'.$poId)
            ->assertOk()
            ->assertJsonPath('purchase_order.buyer_company.id', $buyer->company_id)
            ->assertJsonPath('purchase_order.supplier_company.id', $supplier->company_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function confidentialMultiPartyContext(string $title = 'MPC Deal'): array
    {
        [$buyer, $supplier, $poId] = $this->confirmedPoOnly($title);

        // Strip default mutual identity grants for confidential multi-party assessment.
        $visibility = app(TransactionVisibilityService::class);
        $po = PurchaseOrder::query()->findOrFail($poId);
        $cores = $visibility->ensureCoreParties($po, grantMutualIdentity: false);
        $visibility->revokeIdentity($po, $cores['buyer'], $cores['supplier']);
        $visibility->revokeIdentity($po, $cores['supplier'], $cores['buyer']);

        $i1Company = $this->createIntermediaryCompany('Intermediary One Brokers');
        $i2Company = $this->createIntermediaryCompany('Intermediary Two Brokers');
        $i1 = $this->createIntermediaryUser($i1Company, 'intermediary1@example.com');
        $i2 = $this->createIntermediaryUser($i2Company, 'intermediary2@example.com');

        $i1PartyId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/parties', [
                'company_id' => $i1Company->id,
                'sequence' => 1,
                'role' => 'supplier',
                'commission' => 999,
                'is_internal' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('party.role', PurchaseOrderParty::ROLE_INTERMEDIARY)
            ->json('party.id');

        $i2PartyId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/parties', [
                'company_id' => $i2Company->id,
                'sequence' => 2,
            ])
            ->assertCreated()
            ->json('party.id');

        $c1 = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/commissions', [
                'purchase_order_party_id' => $i1PartyId,
                'amount' => 100,
                'currency' => 'USD',
                'notes' => 'I1 private',
            ])
            ->assertCreated()
            ->json('commission.id');

        $c2 = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/commissions', [
                'purchase_order_party_id' => $i2PartyId,
                'amount' => 200,
                'currency' => 'USD',
                'notes' => 'I2 private',
            ])
            ->assertCreated()
            ->json('commission.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/purchase-orders/'.$poId.'/confidential-notes', [
                'body' => 'Internal margin analysis — secret',
            ])
            ->assertCreated();

        return [
            'buyer' => $buyer,
            'supplier' => $supplier,
            'po_id' => $poId,
            'buyer_party_id' => $cores['buyer']->id,
            'supplier_party_id' => $cores['supplier']->id,
            'supplier_company_name' => $supplier->company->name,
            'intermediary1' => $i1,
            'intermediary2' => $i2,
            'intermediary1_company_id' => $i1Company->id,
            'intermediary2_company_id' => $i2Company->id,
            'intermediary1_company_name' => $i1Company->name,
            'intermediary1_party_id' => $i1PartyId,
            'intermediary2_party_id' => $i2PartyId,
            'commission_1_id' => $c1,
            'commission_2_id' => $c2,
        ];
    }

    private function createIntermediaryCompany(string $name): Company
    {
        $company = new Company;
        $company->name = $name;
        $company->is_buyer = false;
        $company->is_supplier = false;
        $company->save();

        return $company;
    }

    private function createIntermediaryUser(Company $company, string $email): User
    {
        $role = Role::query()->where('name', Role::INTERMEDIARY_USER)->firstOrFail();
        $user = new User;
        $user->name = $email;
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function confirmedPoOnly(string $title): array
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
                'valid_until' => now()->addDays(10)->toDateString(),
                'shipping_amount' => 50,
                'tax_amount' => 25,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 100, 'unit_price' => 10.5]],
            ])
            ->assertCreated()
            ->json('quotation.id');
        $this->actingAs($supplier, 'sanctum')->postJson('/api/supplier/quotations/'.$quotationId.'/submit')->assertOk();
        $negotiationId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/quotations/'.$quotationId.'/negotiation')
            ->assertCreated()
            ->json('negotiation.id');
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
            ])
            ->assertCreated();
        $offerId = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/negotiations/'.$negotiationId.'/offers', [
                'currency' => 'USD',
                'shipping_amount' => 10,
                'tax_amount' => 5,
                'items' => [['rfq_item_id' => $rfqItemId, 'quantity' => 8, 'unit_price' => 90]],
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
