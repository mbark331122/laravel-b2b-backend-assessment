<?php

namespace Tests\Feature\Security;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;

class NotificationSecurityTest extends SecurityTestCase
{
    public function test_cross_company_and_same_company_peer_notification_idor(): void
    {
        $buyerA = $this->userA();
        $buyerB = $this->userB();
        $peer = $this->makeCompanyUser($buyerA->company, 'sec.peer@a.example.com');

        $this->submitRfq($buyerA);
        $this->submitRfq($buyerB);

        $noteA = Notification::query()->where('user_id', $buyerA->id)->firstOrFail();
        $notePeer = Notification::query()->where('user_id', $peer->id)->firstOrFail();
        $noteB = Notification::query()->where('user_id', $buyerB->id)->firstOrFail();

        $this->actingAs($buyerB, 'sanctum')
            ->getJson('/api/notifications/'.$noteA->id)
            ->assertNotFound();

        $this->actingAs($buyerA, 'sanctum')
            ->getJson('/api/notifications/'.$notePeer->id)
            ->assertNotFound();

        $this->actingAs($buyerA, 'sanctum')
            ->postJson('/api/notifications/'.$notePeer->id.'/read')
            ->assertNotFound();

        $this->actingAs($buyerA, 'sanctum')
            ->postJson('/api/notifications/'.$noteB->id.'/read')
            ->assertNotFound();
    }

    public function test_spoofed_company_and_recipient_cannot_create_notifications(): void
    {
        // No public create endpoint — posting to index must fail method or 404/405.
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/notifications', [
                'company_id' => $this->userB()->company_id,
                'user_id' => $this->userB()->id,
                'recipient_id' => $this->userB()->id,
                'type' => 'rfq.submitted',
                'title' => 'Hijack',
                'body' => 'Nope',
            ])
            ->assertStatus(405);
    }

    public function test_mark_all_read_only_affects_current_user(): void
    {
        $buyer = $this->userA();
        $peer = $this->makeCompanyUser($buyer->company, 'markall.peer@a.example.com');
        $this->submitRfq($buyer);

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/notifications/read-all')
            ->assertOk();

        $this->assertTrue(Notification::query()->where('user_id', $buyer->id)->whereNotNull('read_at')->exists());
        $this->assertTrue(Notification::query()->where('user_id', $peer->id)->whereNull('read_at')->exists());
    }

    public function test_missing_permission_gets_403(): void
    {
        $limited = $this->userWithoutPermissions($this->userA()->company, 'nonotify@a.example', [
            Permission::RFQ_READ,
            Permission::RFQ_CREATE,
            Permission::RFQ_SUBMIT,
        ]);

        $this->submitRfq($this->userA());
        $note = Notification::query()->where('user_id', $this->userA()->id)->firstOrFail();

        // Create a notification for limited user manually with their company.
        $n = new Notification;
        $n->company_id = $limited->company_id;
        $n->user_id = $limited->id;
        $n->type = Notification::TYPE_RFQ_SUBMITTED;
        $n->title = 'X';
        $n->body = 'Y';
        $n->dedupe_key = 'manual:'.$limited->id;
        $n->save();

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/notifications')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/notifications/'.$n->id.'/read')
            ->assertForbidden();
    }

    public function test_deactivated_user_cannot_access_notifications(): void
    {
        $manager = $this->userA();
        $member = $this->makeCompanyUser($manager->company, 'deact.notify@a.example.com');
        $this->submitRfq($manager);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$member->id.'/deactivate')
            ->assertOk();

        $this->actingAs($member->fresh(), 'sanctum')
            ->getJson('/api/notifications')
            ->assertForbidden();
    }

    public function test_notification_metadata_strips_identity_fields(): void
    {
        $service = app(\App\Services\NotificationService::class);
        $buyer = $this->userA();

        $service->notifyCompanyUsers(
            (int) $buyer->company_id,
            [$buyer],
            Notification::TYPE_PURCHASE_ORDER_SUBMITTED,
            'PO',
            'Body',
            null,
            [
                'purchase_order_id' => 1,
                'buyer_company_id' => 99,
                'supplier_company_id' => 100,
                'buyer_company' => 'Secret Buyer',
                'commission' => ['amount' => 10],
            ],
        );

        $note = Notification::query()->where('user_id', $buyer->id)->latest('id')->firstOrFail();
        $meta = $note->metadata;
        $this->assertArrayHasKey('purchase_order_id', $meta);
        $this->assertArrayNotHasKey('buyer_company_id', $meta);
        $this->assertArrayNotHasKey('supplier_company_id', $meta);
        $this->assertArrayNotHasKey('buyer_company', $meta);
        $this->assertArrayNotHasKey('commission', $meta);
    }

    private function submitRfq(User $buyer): void
    {
        $rfqId = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/rfqs', [
                'commodity' => 'Sugar',
                'specification' => 'ICUMSA 45',
                'quantity' => 1000,
                'unit' => 'MT',
                'incoterm' => 'CIF',
                'destination' => 'Jeddah',
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->json('rfq.id');

        $this->actingAs($buyer, 'sanctum')->postJson('/api/rfqs/'.$rfqId.'/submit')->assertOk();
    }

    private function makeCompanyUser($company, string $email): User
    {
        $role = Role::query()->where('name', Role::COMPANY_USER)->firstOrFail();
        $user = new User;
        $user->name = 'Peer';
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->is_active = true;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }
}
