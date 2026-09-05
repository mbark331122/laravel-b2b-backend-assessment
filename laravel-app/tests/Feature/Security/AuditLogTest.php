<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Rfq;
use Database\Seeders\SupplierSeeder;

class AuditLogTest extends SecurityTestCase
{
    public function test_rfq_creation_writes_actor_company_action_and_after_value(): void
    {
        $userA = $this->userA();

        $response = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $this->userB()->company_id,
            ]));

        $response->assertCreated();
        $rfqId = $response->json('rfq.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::RFQ_CREATED,
            'actor_id' => $userA->id,
            'company_id' => $userA->company_id,
            'auditable_type' => Rfq::class,
            'auditable_id' => $rfqId,
        ]);

        $audit = AuditLog::query()->where('action', AuditLog::RFQ_CREATED)->firstOrFail();
        $this->assertSame($userA->id, $audit->actor_id);
        $this->assertSame($userA->company_id, $audit->company_id);
        $this->assertSame(25000, $audit->after_value['quantity']);
        $this->assertNotSame($this->userB()->company_id, $audit->company_id);
    }

    public function test_rfq_approval_writes_actor_company_before_and_after_values(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/approve', [
                'proposed_value' => '1',
                'reason' => 'Verified quantity',
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', AuditLog::RFQ_APPROVED)->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($this->userA()->company_id, $audit->company_id);
        $this->assertSame(25000, $audit->before_value['quantity']);
        $this->assertSame(50000, $audit->after_value['quantity']);
        $this->assertSame('Verified quantity', $audit->reason);
        $this->assertSame(50000, $proposal->rfq->fresh()->quantity);
    }

    public function test_bank_change_approval_writes_actor_company_and_iban_before_after(): void
    {
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/approve', [
                'iban' => 'SA00CLIENTBODY0000000001',
                'reason' => 'Documents checked',
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', AuditLog::BANK_ACCOUNT_CHANGED)->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($this->supplierA()->company_id, $audit->company_id);
        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $audit->before_value['iban']);
        $this->assertSame(self::PROPOSED_IBAN, $audit->after_value['iban']);
        $this->assertSame('Documents checked', $audit->reason);
        $this->assertSame(self::PROPOSED_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
        $this->assertDatabaseHas('bank_account_histories', [
            'iban' => SupplierSeeder::SUPPLIER_A_IBAN,
        ]);
    }

    public function test_rejection_is_audited_and_does_not_change_official_values(): void
    {
        $proposal = $this->createQuantityConflict($this->userA()->company);
        $changeRequest = $this->createPendingBankChange($this->supplierA(), $this->userA());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/proposals/'.$proposal->id.'/reject', [
                'reason' => 'Keep original quantity',
            ])
            ->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/bank-change-requests/'.$changeRequest->id.'/reject')
            ->assertOk();

        $proposalAudit = AuditLog::query()->where('action', AuditLog::PROPOSAL_REJECTED)->firstOrFail();
        $this->assertSame($this->admin()->id, $proposalAudit->actor_id);
        $this->assertSame($proposal->id, $proposalAudit->auditable_id);
        $this->assertSame($this->userA()->company_id, $proposalAudit->company_id);
        $this->assertSame(25000, $proposal->rfq->fresh()->quantity);

        $bankAudit = AuditLog::query()->where('action', AuditLog::BANK_CHANGE_REJECTED)->firstOrFail();
        $this->assertSame($changeRequest->id, $bankAudit->auditable_id);
        $this->assertSame(SupplierSeeder::SUPPLIER_A_IBAN, $this->supplierA()->bankAccount->fresh()->iban);
    }

    public function test_audit_company_comes_from_the_resource_not_the_request(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/rfqs', $this->rfqPayload([
                'company_id' => $this->userB()->company_id,
                'actor_id' => $this->userB()->id,
            ]))
            ->assertCreated();

        $audit = AuditLog::query()->where('action', AuditLog::RFQ_CREATED)->firstOrFail();

        $this->assertSame($this->userA()->id, $audit->actor_id);
        $this->assertSame($this->userA()->company_id, $audit->company_id);
        $this->assertNotSame($this->userB()->id, $audit->actor_id);
        $this->assertNotSame($this->userB()->company_id, $audit->company_id);
    }
}
