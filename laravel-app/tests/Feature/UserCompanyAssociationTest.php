<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCompanyAssociationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_a_belongs_to_company_a(): void
    {
        $user = User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();

        $this->assertNotNull($user->company_id);
        $this->assertSame(CompanySeeder::COMPANY_A, $user->company->name);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->hasRole(Role::COMPANY_USER));
    }

    public function test_user_b_belongs_to_company_b(): void
    {
        $user = User::query()->where('email', UserSeeder::USER_B_EMAIL)->firstOrFail();

        $this->assertNotNull($user->company_id);
        $this->assertSame(CompanySeeder::COMPANY_B, $user->company->name);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->hasRole(Role::COMPANY_USER));
    }

    public function test_admin_user_is_not_tied_to_a_company(): void
    {
        $admin = User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();

        $this->assertNull($admin->company_id);
        $this->assertNull($admin->company);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasRole(Role::ADMIN));
        $this->assertFalse($admin->hasRole(Role::COMPANY_USER));
    }
}
