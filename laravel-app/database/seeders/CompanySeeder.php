<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public const COMPANY_A = 'Company A';

    public const COMPANY_B = 'Company B';

    public const SUPPLIER_COMPANY = 'Supplier Company';

    public const SUPPLIER_COMPANY_B = 'Supplier Company B';

    public function run(): void
    {
        $this->seedCompany(self::COMPANY_A, isBuyer: true, isSupplier: false);
        $this->seedCompany(self::COMPANY_B, isBuyer: true, isSupplier: false);
        $this->seedCompany(self::SUPPLIER_COMPANY, isBuyer: false, isSupplier: true);
        $this->seedCompany(self::SUPPLIER_COMPANY_B, isBuyer: false, isSupplier: true);
    }

    private function seedCompany(string $name, bool $isBuyer, bool $isSupplier): void
    {
        $company = Company::query()->firstOrNew(['name' => $name]);
        $company->is_buyer = $isBuyer;
        $company->is_supplier = $isSupplier;
        $company->save();
    }
}
