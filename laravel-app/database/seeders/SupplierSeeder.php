<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public const SUPPLIER_A = 'Supplier A';

    public const SUPPLIER_B = 'Supplier B';

    public const SUPPLIER_A_IBAN = 'SA00AAAA0000000000000001';

    public const SUPPLIER_B_IBAN = 'SA00BBBB0000000000000001';

    public function run(): void
    {
        $companyA = Company::query()->where('name', CompanySeeder::COMPANY_A)->firstOrFail();
        $companyB = Company::query()->where('name', CompanySeeder::COMPANY_B)->firstOrFail();

        $this->createSupplierWithAccount($companyA, self::SUPPLIER_A, 'Company A Beneficiary', 'Bank A', self::SUPPLIER_A_IBAN);
        $this->createSupplierWithAccount($companyB, self::SUPPLIER_B, 'Company B Beneficiary', 'Bank B', self::SUPPLIER_B_IBAN);
    }

    private function createSupplierWithAccount(
        Company $company,
        string $name,
        string $beneficiaryName,
        string $bankName,
        string $iban
    ): void {
        $supplier = $company->suppliers()->firstOrNew(['name' => $name]);
        $supplier->save();

        $account = $supplier->bankAccount ?: new SupplierBankAccount;
        $account->supplier()->associate($supplier);
        $account->beneficiary_name = $beneficiaryName;
        $account->bank_name = $bankName;
        $account->iban = $iban;
        $account->status = SupplierBankAccount::STATUS_ACTIVE;
        $account->save();
    }
}
