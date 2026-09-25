<?php

namespace Tests\Feature\Security;

use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\UserSeeder;

class CompanyOnboardingSecurityTest extends SecurityTestCase
{
    public function test_cross_company_profile_isolation(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->putJson('/api/company/profile', [
                'legal_name' => 'A Legal',
            ])
            ->assertOk();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('company_profile.legal_name', null);

        $limited = $this->userWithoutPermissions($this->userA()->company, 'noprof@a.example', []);

        $this->actingAs($limited, 'sanctum')
            ->getJson('/api/company/profile')
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->putJson('/api/company/profile', ['legal_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_cross_company_address_isolation(): void
    {
        $addressA = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/addresses', [
                'type' => CompanyAddress::TYPE_SHIPPING,
                'address_line' => 'A Street',
                'city' => 'Jeddah',
                'country' => 'SA',
            ])
            ->assertCreated()
            ->json('company_address.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/addresses/'.$addressA)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->putJson('/api/company/addresses/'.$addressA, [
                'city' => 'Hacked',
            ])
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->deleteJson('/api/company/addresses/'.$addressA)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'company_addresses');
    }

    public function test_cross_company_contact_isolation(): void
    {
        $contactA = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/contacts', [
                'name' => 'Contact A',
            ])
            ->assertCreated()
            ->json('company_contact.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/contacts/'.$contactA)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->patchJson('/api/company/contacts/'.$contactA, [
                'name' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_cross_company_member_isolation(): void
    {
        $this->actingAs($this->userA(), 'sanctum')
            ->getJson('/api/company/members/'.$this->userB()->id)
            ->assertNotFound();

        $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/members/'.$this->userB()->id.'/deactivate')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/members')
            ->assertOk()
            ->assertJsonMissing(['email' => UserSeeder::USER_A_EMAIL]);
    }

    public function test_cross_company_invitation_isolation(): void
    {
        $invite = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'secret@company-a.example',
                'role' => Role::COMPANY_USER,
            ])
            ->assertCreated();

        $id = $invite->json('company_invitation.id');

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/invitations/'.$id)
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->postJson('/api/company/invitations/'.$id.'/revoke')
            ->assertNotFound();

        $this->actingAs($this->userB(), 'sanctum')
            ->getJson('/api/company/invitations')
            ->assertOk()
            ->assertJsonMissing(['email' => 'secret@company-a.example']);
    }

    public function test_spoofed_company_id_on_mutations_is_ignored(): void
    {
        $user = $this->userA();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/addresses', [
                'type' => CompanyAddress::TYPE_REGISTERED,
                'address_line' => 'Reg',
                'city' => 'Riyadh',
                'country' => 'SA',
                'company_id' => $this->userB()->company_id,
            ])
            ->assertCreated()
            ->assertJsonPath('company_address.company_id', $user->company_id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/company/contacts', [
                'name' => 'No Spoof',
                'company_id' => $this->userB()->company_id,
                'user_id' => $this->userB()->id,
            ])
            ->assertCreated()
            ->assertJsonPath('company_contact.company_id', $user->company_id)
            ->assertJsonMissingPath('company_contact.user_id');
    }

    public function test_deactivated_member_cannot_use_protected_endpoints(): void
    {
        $manager = $this->userA();
        $member = $this->userWithoutPermissions(
            $manager->company,
            'deact@a.example',
            Permission::companyOnboardingNames(),
        );

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/company/members/'.$member->id.'/deactivate')
            ->assertOk();

        $deactivated = $member->fresh();
        $this->assertFalse($deactivated->isActiveMember());

        $this->actingAs($deactivated, 'sanctum')
            ->getJson('/api/company/profile')
            ->assertForbidden();

        $this->actingAs($deactivated, 'sanctum')
            ->getJson('/api/rfqs')
            ->assertForbidden();
    }

    public function test_unauthorized_without_permissions_gets_403(): void
    {
        $limited = $this->userWithoutPermissions($this->userA()->company, 'limited.onboard@a.example', [
            Permission::COMPANY_PROFILE_READ,
        ]);

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/company/addresses', [
                'type' => CompanyAddress::TYPE_BILLING,
                'address_line' => 'X',
                'city' => 'Y',
                'country' => 'SA',
            ])
            ->assertForbidden();

        $this->actingAs($limited, 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'x@example.com',
                'role' => Role::COMPANY_USER,
            ])
            ->assertForbidden();
    }

    public function test_invitation_acceptance_cannot_redirect_tenant(): void
    {
        $invite = $this->actingAs($this->userA(), 'sanctum')
            ->postJson('/api/company/invitations', [
                'email' => 'tenant.lock@company-a.example',
                'role' => Role::COMPANY_USER,
            ])
            ->assertCreated();

        $response = $this->postJson('/api/invitations/accept', [
            'token' => $invite->json('invitation_token'),
            'name' => 'Locked Tenant',
            'password' => 'password123',
            'company_id' => $this->supplierUser()->company_id,
        ])->assertCreated();

        $response->assertJsonPath('user.company.id', $this->userA()->company_id);
        $this->assertNotSame(
            $this->supplierUser()->company_id,
            $response->json('user.company.id'),
        );
    }
}
