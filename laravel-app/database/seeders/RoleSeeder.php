<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::query()->firstOrCreate(['name' => Role::ADMIN]);
        $companyUser = Role::query()->firstOrCreate(['name' => Role::COMPANY_USER]);
        $supplierUser = Role::query()->firstOrCreate(['name' => Role::SUPPLIER_USER]);
        $intermediaryUser = Role::query()->firstOrCreate(['name' => Role::INTERMEDIARY_USER]);

        $admin->permissions()->sync(
            Permission::query()->whereIn('name', Permission::names())->pluck('id')
        );

        $companyUser->permissions()->sync(
            Permission::query()->whereIn('name', Permission::companyUserNames())->pluck('id')
        );

        $supplierUser->permissions()->sync(
            Permission::query()->whereIn('name', Permission::supplierUserNames())->pluck('id')
        );

        $intermediaryUser->permissions()->sync(
            Permission::query()->whereIn('name', Permission::intermediaryUserNames())->pluck('id')
        );
    }
}
