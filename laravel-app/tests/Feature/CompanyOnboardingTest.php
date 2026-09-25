<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\CompanyInvitation;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\UserSeeder;
use Tests\Feature\Security\SecurityTestCase;

class CompanyOnboardingTest extends SecurityTestCase
{
    public function test_company_can_upsert_and_read_business_profile(): void
    {
        $user = $this->userA();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/company/profile', [
                'legal_name' => 'Company A Legal LLC',
                'business_description' => 'Buyer trading company',
                'registration_number' => 'CR-100',
                'tax_identifier' => 'TAX-100',
                'website' => 'https://company-a.example',
                'primary_email' => 'ops@company-a.example',
                'primary_phone' => '+966500000001',
                'company_id' => 999,
                'is_buyer' => false,
            ])
            ->assertOk()
            ->assertJsonPath('company_profile.legal_name', 'Company A Legal LLC')
            ->assertJsonPath('company_profile.tax_identifier', 'TAX-100')
            ->assertJsonPath('company_profile.company_id', $user->company_id)
            ->assertJsonMissingPath('company_profile.is_buyer');

        $this->assertDatabaseHas('company_profiles', [
            'company_id' => $user->company_id,
            'tax_identifier' => 'TAX-100',
        ]);

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('company.name', 'Company A')
            ->assertJsonPath('company_profile.tax_identifier', 'TAX-100');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::COMPANY_PROFILE_UPDATED,
            'company_id' => $user->company_id,
        ]);
    }

    public function test_company_address_crud_and_default_per_type(): void
    {
        $user = $this->userA();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/addresses', [
                'type' => CompanyAddress::TYPE_BILLING,
                'address_line' => '1 King Road',
                'city' => 'Riyadh',
                'country' => 'SA',
                'is_default' => true,
                'company_id' => 999,
            ])
            ->assertCreated()
            ->json('company_address');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/addresses', [
                'type' => CompanyAddress::TYPE_BILLING,
                'address_line' => '2 King Road',
                'city' => 'Riyadh',
                'country' => 'SA',
                'is_default' => true,
            ])
            ->assertCreated()
            ->json('company_address');

        $this->assertFalse(CompanyAddress::query()->findOrFail($first['id'])->is_default);
        $this->assertTrue(CompanyAddress::query()->findOrFail($second['id'])->is_default);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/company/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'company_addresses');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/company/addresses/'.$second['id'], [
                'label' => 'HQ Billing',
            ])
            ->assertOk()
            ->assertJsonPath('company_address.label', 'HQ Billing');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/company/addresses/'.$first['id'])
            ->assertOk();

        $this->assertDatabaseMissing('company_addresses', ['id' => $first['id']]);
    }

    public function test_company_contact_crud(): void
    {
        $user = $this->userA();

        $contact = $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/contacts', [
                'name' => 'Sara Ops',
                'job_title' => 'Procurement Lead',
                'email' => 'sara@company-a.example',
                'phone' => '+966500000010',
                'contact_type' => CompanyContact::TYPE_BILLING,
                'user_id' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('company_contact.name', 'Sara Ops')
            ->json('company_contact');

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/company/contacts/'.$contact['id'], [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('company_contact.is_active', false);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/company/contacts/'.$contact['id'])
            ->assertOk();
    }

    public function test_company_members_list_and_activation_cycle(): void
    {
        $manager = $this->userA();
        $member = $this->userWithoutPermissions(
            $manager->company,
            'member.a@example.com',
            Permission::companyOnboardingNames(),
        );

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/company/members')
            ->assertOk()
            ->assertJsonFragment(['email' => $manager->email])
            ->assertJsonFragment(['email' => $member->email]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$member->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('company_member.is_active', false);

        $this->postJson('/api/login', [
            'email' => $member->email,
            'password' => UserSeeder::PASSWORD,
        ])->assertForbidden();

        $this->actingAs($member->fresh(), 'sanctum')
            ->getJson('/api/company/profile')
            ->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$member->id.'/activate')
            ->assertOk()
            ->assertJsonPath('company_member.is_active', true);

        $this->postJson('/api/login', [
            'email' => $member->email,
            'password' => UserSeeder::PASSWORD,
        ])->assertOk();
    }

    public function test_member_cannot_deactivate_self_or_last_manager(): void
    {
        $manager = $this->userA();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$manager->id.'/deactivate')
            ->assertStatus(422);

        // Only one active member-manager in company A.
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$manager->id.'/deactivate')
            ->assertStatus(422);
    }

    public function test_invitation_create_accept_and_token_not_listed(): void
    {
        $manager = $this->userA();

        $create = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'newbie@company-a.example',
                'role' => Role::COMPANY_USER,
                'company_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('company_invitation.email', 'newbie@company-a.example')
            ->assertJsonPath('company_invitation.status', CompanyInvitation::STATUS_PENDING)
            ->assertJsonMissingPath('company_invitation.token_hash');

        $token = $create->json('invitation_token');
        $this->assertNotEmpty($token);
        $invitationId = $create->json('company_invitation.id');

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/company/invitations/'.$invitationId)
            ->assertOk()
            ->assertJsonMissingPath('invitation_token')
            ->assertJsonMissingPath('company_invitation.token_hash');

        $accept = $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'name' => 'Newbie User',
            'password' => 'password123',
            'company_id' => $this->userB()->company_id,
            'role' => Role::ADMIN,
        ])->assertCreated();

        $accept->assertJsonPath('user.email', 'newbie@company-a.example')
            ->assertJsonPath('user.company.id', $manager->company_id)
            ->assertJsonPath('user.role', Role::COMPANY_USER)
            ->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('company_invitations', [
            'id' => $invitationId,
            'status' => CompanyInvitation::STATUS_ACCEPTED,
            'pending_lock' => null,
        ]);

        $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'name' => 'Reuse Attempt',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_duplicate_pending_invitation_is_rejected(): void
    {
        $manager = $this->userA();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'dup@company-a.example',
                'role' => Role::COMPANY_USER,
            ])
            ->assertCreated();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'dup@company-a.example',
                'role' => Role::COMPANY_USER,
            ])
            ->assertStatus(422);
    }

    public function test_expired_and_revoked_invitations_cannot_be_accepted(): void
    {
        $manager = $this->userA();

        $expiredCreate = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'expire@company-a.example',
                'role' => Role::COMPANY_USER,
                'expires_in_days' => 1,
            ])
            ->assertCreated();

        $invitation = CompanyInvitation::query()->findOrFail($expiredCreate->json('company_invitation.id'));
        $invitation->expires_at = now()->subMinute();
        $invitation->save();

        $this->postJson('/api/invitations/accept', [
            'token' => $expiredCreate->json('invitation_token'),
            'name' => 'Too Late',
            'password' => 'password123',
        ])->assertStatus(422);

        $revokedCreate = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'revoke@company-a.example',
                'role' => Role::COMPANY_USER,
            ])
            ->assertCreated();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/invitations/'.$revokedCreate->json('company_invitation.id').'/revoke')
            ->assertOk()
            ->assertJsonPath('company_invitation.status', CompanyInvitation::STATUS_REVOKED);

        $this->postJson('/api/invitations/accept', [
            'token' => $revokedCreate->json('invitation_token'),
            'name' => 'Revoked User',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_supplier_invitation_uses_supplier_role(): void
    {
        $supplier = $this->supplierUser();

        $create = $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'new.supplier@example.com',
                'role' => Role::SUPPLIER_USER,
            ])
            ->assertCreated();

        $this->postJson('/api/invitations/accept', [
            'token' => $create->json('invitation_token'),
            'name' => 'New Supplier',
            'password' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', Role::SUPPLIER_USER)
            ->assertJsonPath('user.company.id', $supplier->company_id);
    }

    public function test_buyer_cannot_invite_supplier_role(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'wrong-role@example.com',
                'role' => Role::SUPPLIER_USER,
            ])
            ->assertStatus(422);
    }

    public function test_me_includes_is_active(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.is_active', true);
    }
}
