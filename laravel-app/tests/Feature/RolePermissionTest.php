<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_required_permissions_exist(): void
    {
        $this->assertEqualsCanonicalizing(
            Permission::names(),
            Permission::query()->pluck('name')->all()
        );
    }

    public function test_required_roles_exist(): void
    {
        $this->assertTrue(Role::query()->where('name', Role::ADMIN)->exists());
        $this->assertTrue(Role::query()->where('name', Role::COMPANY_USER)->exists());
    }

    public function test_admin_role_has_all_permissions(): void
    {
        $admin = Role::query()->where('name', Role::ADMIN)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            Permission::names(),
            $admin->permissions->pluck('name')->all()
        );
    }

    public function test_company_user_role_has_operational_permissions(): void
    {
        $role = Role::query()->where('name', Role::COMPANY_USER)->firstOrFail();

        $this->assertEqualsCanonicalizing(
            Permission::companyUserNames(),
            $role->permissions->pluck('name')->all()
        );

        $this->assertFalse($role->permissions->contains('name', Permission::RFQ_APPROVE));
        $this->assertFalse($role->permissions->contains('name', Permission::BANK_APPROVE));
    }

    public function test_seeded_users_receive_permissions_through_their_role(): void
    {
        $userA = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();

        $this->assertTrue($userA->hasPermission(Permission::RFQ_READ));
        $this->assertTrue($userA->hasPermission(Permission::BANK_CHANGE_REQUEST));
        $this->assertFalse($userA->hasPermission(Permission::RFQ_APPROVE));
        $this->assertFalse($userA->hasPermission(Permission::BANK_APPROVE));

        $this->assertTrue($admin->hasPermission(Permission::RFQ_APPROVE));
        $this->assertTrue($admin->hasPermission(Permission::BANK_APPROVE));
    }
}
