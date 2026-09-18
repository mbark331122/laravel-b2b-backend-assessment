<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Database\Seeders\CompanySeeder;

class CompanyClassificationAccessTest extends SecurityTestCase
{
    public function test_supplier_company_user_cannot_create_rfq(): void
    {
        $supplierUser = $this->supplierUser();

        $this->assertTrue($supplierUser->isSupplierUser());
        $this->assertFalse($supplierUser->isBuyerUser());
        $this->assertTrue($supplierUser->hasPermission('rfq.create'));

        $this->actingAs($supplierUser, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $this->userA()->company_id,
                'is_buyer' => true,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('rfqs', 0);
    }

    public function test_buyer_company_user_can_create_rfq_for_own_company_only(): void
    {
        $response = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $this->userB()->company_id,
            ]))
            ->assertCreated();

        $rfqId = $response->json('rfq.id');

        $this->assertDatabaseHas('rfqs', [
            'id' => $rfqId,
            'company_id' => $this->userA()->company_id,
        ]);
        $this->assertDatabaseMissing('rfqs', [
            'id' => $rfqId,
            'company_id' => $this->userB()->company_id,
        ]);
    }

    public function test_supplier_company_user_cannot_access_buyer_company_rfq_or_bank_data(): void
    {
        $rfq = $this->createOfficialRfq($this->userA()->company);
        $supplierUser = $this->supplierUser();

        $this->actingAs($supplierUser, 'sanctum')
            ->getJson('/api/rfqs/'.$rfq->id)
            ->assertNotFound();

        $this->actingAs($supplierUser, 'sanctum')
            ->getJson('/api/suppliers/'.$this->supplierA()->id.'/bank-account')
            ->assertNotFound();

        $this->actingAs($supplierUser, 'sanctum')
            ->putJson('/api/rfqs/'.$rfq->id, $this->rfqPayload([
                'destination' => 'Hijacked',
                'company_id' => $supplierUser->company_id,
            ]))
            ->assertNotFound();

        $this->assertSame('Jeddah', $rfq->fresh()->destination);
        $this->assertSame($this->userA()->company_id, $rfq->fresh()->company_id);
    }

    public function test_supplier_company_user_rfq_list_stays_empty_and_ignores_company_id_query(): void
    {
        $this->createOfficialRfq($this->userA()->company);
        $this->createOfficialRfq($this->userB()->company);

        $this->actingAs($this->supplierUser(), 'sanctum')
            ->getJson('/api/rfqs?company_id='.$this->userA()->company_id)
            ->assertOk()
            ->assertJsonCount(0, 'rfqs');
    }

    public function test_audit_foundation_uses_authenticated_actor_and_resource_company(): void
    {
        $this->actingAs($this->userA(), 'sanctum');

        $rfq = $this->createOfficialRfq($this->userA()->company);

        app(AuditLogger::class)->record(
            AuditLog::RFQ_CREATED,
            $rfq,
            $rfq->company_id,
            null,
            ['commodity' => $rfq->commodity],
            'sprint-1-foundation'
        );

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->userA()->id,
            'company_id' => $this->userA()->company_id,
            'action' => AuditLog::RFQ_CREATED,
            'auditable_type' => $rfq::class,
            'auditable_id' => $rfq->id,
            'reason' => 'sprint-1-foundation',
        ]);

        $log = AuditLog::query()->where('auditable_id', $rfq->id)->firstOrFail();
        $this->assertSame(['commodity' => 'Sugar'], $log->after_value);
        $this->assertNotSame(CompanySeeder::SUPPLIER_COMPANY, $log->company->name);
    }
}
