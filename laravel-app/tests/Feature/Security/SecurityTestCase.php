<?php

namespace Tests\Feature\Security;

use App\Models\AiExtraction;
use App\Models\BankChangeRequest;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Rfq;
use App\Models\RfqProposal;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PROPOSED_IBAN = 'SA00AAAA0000000000000099';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    protected function userA(): User
    {
        return User::query()->where('email', UserSeeder::USER_A_EMAIL)->firstOrFail();
    }

    protected function userB(): User
    {
        return User::query()->where('email', UserSeeder::USER_B_EMAIL)->firstOrFail();
    }

    protected function admin(): User
    {
        return User::query()->where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
    }

    protected function supplierA(): Supplier
    {
        return Supplier::query()->where('name', SupplierSeeder::SUPPLIER_A)->firstOrFail();
    }

    protected function supplierB(): Supplier
    {
        return Supplier::query()->where('name', SupplierSeeder::SUPPLIER_B)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createOfficialRfq(Company $company, array $overrides = []): Rfq
    {
        return $company->rfqs()->create([
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 25000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            'status' => Rfq::STATUS_DRAFT,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function rfqPayload(array $overrides = []): array
    {
        return [
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 25000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            ...$overrides,
        ];
    }

    protected function createQuantityConflict(Company $company): RfqProposal
    {
        $rfq = $this->createOfficialRfq($company);

        $extraction = $rfq->aiExtractions()->create([
            'commodity' => 'Sugar',
            'specification' => 'ICUMSA 45',
            'quantity' => 50000,
            'unit' => 'MT',
            'incoterm' => 'CIF',
            'destination' => 'Jeddah',
            'confidence' => 0.9,
            'source' => 'AI/mock',
            'status' => AiExtraction::STATUS_PENDING,
        ]);

        $proposal = new RfqProposal;
        $proposal->rfq()->associate($rfq);
        $proposal->aiExtraction()->associate($extraction);
        $proposal->field = 'quantity';
        $proposal->current_value = '25000';
        $proposal->proposed_value = '50000';
        $proposal->source = 'AI/mock';
        $proposal->confidence = 0.9;
        $proposal->status = RfqProposal::STATUS_PENDING;
        $proposal->save();

        return $proposal;
    }

    protected function createPendingBankChange(Supplier $supplier, User $user): BankChangeRequest
    {
        $account = $supplier->bankAccount;

        $changeRequest = new BankChangeRequest;
        $changeRequest->bankAccount()->associate($account);
        $changeRequest->company()->associate($supplier->company);
        $changeRequest->requestedBy()->associate($user);
        $changeRequest->current_iban = $account->iban;
        $changeRequest->proposed_iban = self::PROPOSED_IBAN;
        $changeRequest->status = BankChangeRequest::STATUS_PENDING;
        $changeRequest->save();

        return $changeRequest;
    }

    protected function userWithoutPermissions(Company $company, string $email, array $permissionNames = []): User
    {
        $role = Role::query()->create(['name' => 'limited-'.$email]);
        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user = new User;
        $user->name = 'Limited User';
        $user->email = $email;
        $user->password = UserSeeder::PASSWORD;
        $user->company()->associate($company);
        $user->role()->associate($role);
        $user->save();

        return $user;
    }
}
