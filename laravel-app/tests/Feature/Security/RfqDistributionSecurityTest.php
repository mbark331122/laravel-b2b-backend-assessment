<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;

class RfqDistributionSecurityTest extends SecurityTestCase
{
    public function test_cross_company_buyer_cannot_match_or_distribute_another_rfq(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/distributions')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
                'company_id' => $this->userA()->company_id,
                'buyer_company_id' => $this->userA()->company_id,
                'tenant_id' => $this->userA()->company_id,
            ])
            ->assertNotFound();
    }

    public function test_supplier_company_spoofing_cannot_read_another_suppliers_distribution(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertCreated();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/rfqs/'.$rfqId.'?supplier_id='.$this->supplierUser()->company_id.'&supplier_company_id='.$this->supplierUser()->company_id.'&company_id='.$this->supplierUser()->company_id.'&tenant_id='.$this->supplierUser()->company_id)
            ->assertNotFound();

        $this->actingAs($this->supplierUserB(), 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertOk()
            ->assertJsonCount(0, 'rfqs');
    }

    public function test_supplier_cannot_mutate_rfq_or_distribution(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->json('distributions.0.id');

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw')
            ->assertForbidden();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->putJson('/api/rfqs/'.$rfqId, [
                'commodity' => 'Hack',
                'specification' => 'Hack',
                'quantity' => 1,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Hack',
            ])
            ->assertForbidden();

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/close')
            ->assertForbidden();
    }

    public function test_cross_company_withdraw_is_not_found(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $distributionId = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->json('distributions.0.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions/'.$distributionId.'/withdraw')
            ->assertNotFound();
    }

    public function test_missing_distribution_permissions_are_forbidden_on_own_rfq(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $limited = $this->userWithoutPermissions(
            $this->userA()->company,
            'no.dist.perm@example.com',
            [Permission::RFQ_READ, Permission::RFQ_CREATE, Permission::RFQ_SUBMIT]
        );

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/distributions')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertForbidden();
    }

    public function test_supplier_without_rfq_read_permission_cannot_use_inbox(): void
    {
        $limited = $this->userWithoutPermissions(
            $this->supplierUser()->company,
            'no.supplier.rfq@example.com',
            [Permission::PRODUCT_READ, Permission::SUPPLIER_PROFILE_READ]
        );

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/supplier/rfqs')
            ->assertForbidden();
    }

    public function test_inactive_supplier_cannot_receive_distribution(): void
    {
        $product = Product::query()->where('sku', 'SUG-45')->firstOrFail();
        $profileA = SupplierProfile::query()->where('display_name', 'Supplier Company Trading')->firstOrFail();
        $profileA->status = SupplierProfile::STATUS_INACTIVE;
        $profileA->save();

        $rfqId = $this->createSubmittedRfq($this->userA(), $product);

        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/rfqs/'.$rfqId.'/suppliers')
            ->assertOk()
            ->assertJsonCount(0, 'suppliers');

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/distributions', [
                'supplier_profile_ids' => [$profileA->id],
            ])
            ->assertUnprocessable();
    }

    private function createSubmittedRfq($buyer, Product $product): int
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'title' => 'Secure RFQ',
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 500,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 500],
                ],
            ])
            ->assertCreated()
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs/'.$rfqId.'/submit')
            ->assertOk()
            ->assertJsonPath('rfq.status', Rfq::STATUS_SUBMITTED);

        return $rfqId;
    }
}
