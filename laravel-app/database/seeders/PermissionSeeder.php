<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::names() as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }
}
