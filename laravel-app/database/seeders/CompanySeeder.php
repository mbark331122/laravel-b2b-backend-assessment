<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public const COMPANY_A = 'Company A';

    public const COMPANY_B = 'Company B';

    public function run(): void
    {
        Company::query()->firstOrCreate(['name' => self::COMPANY_A]);
        Company::query()->firstOrCreate(['name' => self::COMPANY_B]);
    }
}
