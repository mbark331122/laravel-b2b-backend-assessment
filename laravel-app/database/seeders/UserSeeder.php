<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const USER_A_EMAIL = 'user.a@example.com';

    public const USER_B_EMAIL = 'user.b@example.com';

    public const SUPPLIER_USER_EMAIL = 'supplier.user@example.com';

    public const SUPPLIER_USER_B_EMAIL = 'supplier.user.b@example.com';

    public const ADMIN_EMAIL = 'admin@example.com';

    public function run(): void
    {
        $companyUserRole = Role::query()->where('name', Role::COMPANY_USER)->firstOrFail();
        $supplierUserRole = Role::query()->where('name', Role::SUPPLIER_USER)->firstOrFail();
        $adminRole = Role::query()->where('name', Role::ADMIN)->firstOrFail();

        $companyA = Company::query()->where('name', CompanySeeder::COMPANY_A)->firstOrFail();
        $companyB = Company::query()->where('name', CompanySeeder::COMPANY_B)->firstOrFail();
        $supplierCompany = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY)->firstOrFail();
        $supplierCompanyB = Company::query()->where('name', CompanySeeder::SUPPLIER_COMPANY_B)->firstOrFail();

        $this->createUser('User A', self::USER_A_EMAIL, $companyUserRole, $companyA);
        $this->createUser('User B', self::USER_B_EMAIL, $companyUserRole, $companyB);
        $this->createUser('Supplier User', self::SUPPLIER_USER_EMAIL, $supplierUserRole, $supplierCompany);
        $this->createUser('Supplier User B', self::SUPPLIER_USER_B_EMAIL, $supplierUserRole, $supplierCompanyB);
        $this->createUser('Admin User', self::ADMIN_EMAIL, $adminRole);
    }

    private function createUser(string $name, string $email, Role $role, ?Company $company = null): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = self::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();
    }
}
