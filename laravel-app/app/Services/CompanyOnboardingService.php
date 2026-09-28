<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\CompanyContact;
use App\Models\CompanyInvitation;
use App\Models\CompanyProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyOnboardingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertProfile(Company $company, array $payload): CompanyProfile
    {
        $profile = $company->profile ?? new CompanyProfile;
        $before = $profile->exists ? $profile->toApiArray() : null;

        // company_id and classification never come from the client.
        $profile->fill($payload);
        $profile->company()->associate($company);
        $profile->save();
        $company->setRelation('profile', $profile);

        $after = $profile->fresh()->toApiArray();

        $this->auditLogger->record(
            AuditLog::COMPANY_PROFILE_UPDATED,
            $profile,
            $company->id,
            before: $before,
            after: $after,
        );

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createAddress(Company $company, array $payload): CompanyAddress
    {
        return DB::transaction(function () use ($company, $payload) {
            $address = new CompanyAddress($payload);
            $address->company()->associate($company);
            $address->is_default = (bool) ($payload['is_default'] ?? false);
            $address->save();

            if ($address->is_default) {
                $this->clearOtherDefaults($company, $address);
            }

            $this->auditLogger->record(
                AuditLog::COMPANY_ADDRESS_CREATED,
                $address,
                $company->id,
                after: $address->toApiArray(),
            );

            return $address->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateAddress(CompanyAddress $address, array $payload): CompanyAddress
    {
        return DB::transaction(function () use ($address, $payload) {
            $before = $address->toApiArray();
            $address->fill($payload);
            if (array_key_exists('is_default', $payload)) {
                $address->is_default = (bool) $payload['is_default'];
            }
            $address->save();

            if ($address->is_default) {
                $this->clearOtherDefaults($address->company, $address);
            }

            $after = $address->fresh()->toApiArray();

            $this->auditLogger->record(
                AuditLog::COMPANY_ADDRESS_UPDATED,
                $address,
                $address->company_id,
                before: $before,
                after: $after,
            );

            return $address->fresh();
        });
    }

    public function deleteAddress(CompanyAddress $address): void
    {
        $before = $address->toApiArray();
        $companyId = $address->company_id;
        $address->delete();

        $this->auditLogger->record(
            AuditLog::COMPANY_ADDRESS_DELETED,
            $address,
            $companyId,
            before: $before,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createContact(Company $company, array $payload): CompanyContact
    {
        $contact = new CompanyContact($payload);
        $contact->company()->associate($company);
        $contact->contact_type = $payload['contact_type'] ?? CompanyContact::TYPE_GENERAL;
        $contact->is_active = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
        $contact->save();

        $this->auditLogger->record(
            AuditLog::COMPANY_CONTACT_CREATED,
            $contact,
            $company->id,
            after: $contact->toApiArray(),
        );

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateContact(CompanyContact $contact, array $payload): CompanyContact
    {
        $before = $contact->toApiArray();
        $contact->fill($payload);
        $contact->save();
        $after = $contact->fresh()->toApiArray();

        $this->auditLogger->record(
            AuditLog::COMPANY_CONTACT_UPDATED,
            $contact,
            $contact->company_id,
            before: $before,
            after: $after,
        );

        return $contact->fresh();
    }

    public function deleteContact(CompanyContact $contact): void
    {
        $before = $contact->toApiArray();
        $companyId = $contact->company_id;
        $contact->delete();

        $this->auditLogger->record(
            AuditLog::COMPANY_CONTACT_DELETED,
            $contact,
            $companyId,
            before: $before,
        );
    }

    public function activateMember(User $actor, User $member): User
    {
        $this->assertSameCompanyMember($actor, $member);

        if ($member->isActiveMember()) {
            return $member;
        }

        $before = $member->toMemberApiArray();
        $member->is_active = true;
        $member->save();

        $this->auditLogger->record(
            AuditLog::COMPANY_MEMBER_ACTIVATED,
            $member,
            $member->company_id,
            before: $before,
            after: $member->fresh()->toMemberApiArray(),
        );

        return $member->fresh();
    }

    public function deactivateMember(User $actor, User $member): User
    {
        $this->assertSameCompanyMember($actor, $member);

        if ($actor->id === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'You cannot deactivate your own membership.',
            ]);
        }

        if (! $member->isActiveMember()) {
            return $member;
        }

        $this->assertNotLastMemberManager($member);

        $before = $member->toMemberApiArray();
        $member->is_active = false;
        $member->save();

        // Revoke API tokens so deactivated members cannot continue with existing sessions.
        $member->tokens()->delete();

        $this->auditLogger->record(
            AuditLog::COMPANY_MEMBER_DEACTIVATED,
            $member,
            $member->company_id,
            before: $before,
            after: $member->fresh()->toMemberApiArray(),
        );

        return $member->fresh();
    }

    /**
     * @return array{invitation: CompanyInvitation, plain_token: string}
     */
    public function createInvitation(User $actor, Company $company, string $email, string $roleName, ?int $expiresInDays = null): array
    {
        $email = strtolower(trim($email));
        $role = $this->resolveInvitableRole($company, $roleName);

        if (User::query()->where('email', $email)->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'A member with this email already belongs to the company.',
            ]);
        }

        if (User::query()->where('email', $email)->whereNotNull('company_id')->where('company_id', '!=', $company->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email already belongs to another company.',
            ]);
        }

        return DB::transaction(function () use ($actor, $company, $email, $role, $expiresInDays) {
            CompanyInvitation::query()
                ->where('company_id', $company->id)
                ->where('email', $email)
                ->where('status', CompanyInvitation::STATUS_PENDING)
                ->lockForUpdate()
                ->get()
                ->each(function (CompanyInvitation $existing): void {
                    if ($existing->refreshExpiration()) {
                        $this->auditLogger->record(
                            AuditLog::COMPANY_INVITATION_EXPIRED,
                            $existing,
                            $existing->company_id,
                            after: $existing->toApiArray(),
                        );
                    }
                });

            if (CompanyInvitation::query()
                ->where('pending_lock', CompanyInvitation::pendingLockKey($company->id, $email))
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An active invitation already exists for this email.',
                ]);
            }

            $days = $expiresInDays ?? 7;
            $issued = CompanyInvitation::issue(
                $company,
                $email,
                $role,
                $actor,
                now()->addDays(max(1, min(30, $days))),
            );

            $this->auditLogger->record(
                AuditLog::COMPANY_INVITATION_CREATED,
                $issued['invitation'],
                $company->id,
                after: $issued['invitation']->toApiArray(),
            );

            return $issued;
        });
    }

    public function revokeInvitation(CompanyInvitation $invitation): CompanyInvitation
    {
        $invitation->refreshExpiration();

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'Only pending invitations can be revoked.',
            ]);
        }

        $before = $invitation->toApiArray();
        $invitation->status = CompanyInvitation::STATUS_REVOKED;
        $invitation->pending_lock = null;
        $invitation->save();

        $this->auditLogger->record(
            AuditLog::COMPANY_INVITATION_REVOKED,
            $invitation,
            $invitation->company_id,
            before: $before,
            after: $invitation->toApiArray(),
        );

        return $invitation->fresh();
    }

    /**
     * Accept an invitation by plain token. Company/role are derived from the invitation only.
     *
     * @param  array{name: string, password: string, company_id?: mixed}  $payload
     * @return array{user: User, token: string}
     */
    public function acceptInvitation(string $plainToken, array $payload): array
    {
        return DB::transaction(function () use ($plainToken, $payload) {
            $invitation = CompanyInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null) {
                throw ValidationException::withMessages([
                    'token' => 'Invalid invitation token.',
                ]);
            }

            if ($invitation->refreshExpiration()) {
                $this->auditLogger->record(
                    AuditLog::COMPANY_INVITATION_EXPIRED,
                    $invitation,
                    $invitation->company_id,
                    after: $invitation->toApiArray(),
                );

                throw ValidationException::withMessages([
                    'token' => 'This invitation has expired.',
                ]);
            }

            if ($invitation->status !== CompanyInvitation::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'token' => 'This invitation cannot be accepted.',
                ]);
            }

            // Client company_id is ignored entirely — ownership comes from the invitation.
            unset($payload['company_id'], $payload['role'], $payload['role_id'], $payload['company']);

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'A user with this email already exists.',
                ]);
            }

            $user = new User;
            $user->name = $payload['name'];
            $user->email = $invitation->email;
            $user->password = $payload['password'];
            $user->is_active = true;
            $user->company()->associate($invitation->company);
            $user->role()->associate($invitation->role);
            $user->save();

            $invitation->status = CompanyInvitation::STATUS_ACCEPTED;
            $invitation->pending_lock = null;
            $invitation->accepted_at = now();
            $invitation->acceptedUser()->associate($user);
            $invitation->save();

            $this->auditLogger->record(
                AuditLog::COMPANY_INVITATION_ACCEPTED,
                $invitation,
                $invitation->company_id,
                after: [
                    'invitation' => $invitation->toApiArray(),
                    'user_id' => $user->id,
                    'role' => $user->role?->name,
                ],
            );

            return [
                'user' => $user->fresh(['company', 'role.permissions']),
                'token' => $user->createToken('api')->plainTextToken,
            ];
        });
    }

    private function clearOtherDefaults(Company $company, CompanyAddress $keep): void
    {
        CompanyAddress::query()
            ->where('company_id', $company->id)
            ->where('type', $keep->type)
            ->whereKeyNot($keep->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function assertSameCompanyMember(User $actor, User $member): void
    {
        if ($member->company_id === null || $actor->company_id !== $member->company_id) {
            abort(404);
        }
    }

    private function assertNotLastMemberManager(User $member): void
    {
        if (! $member->hasPermission(Permission::COMPANY_MEMBER_MANAGE)) {
            return;
        }

        $otherManagers = User::query()
            ->where('company_id', $member->company_id)
            ->where('is_active', true)
            ->whereKeyNot($member->id)
            ->with('role.permissions')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission(Permission::COMPANY_MEMBER_MANAGE));

        if ($otherManagers->isEmpty()) {
            throw ValidationException::withMessages([
                'member' => 'Cannot deactivate the last active member with member management permission.',
            ]);
        }
    }

    private function resolveInvitableRole(Company $company, string $roleName): Role
    {
        $allowed = [];

        if ($company->isBuyer()) {
            $allowed[] = Role::COMPANY_USER;
        }

        if ($company->isSupplier()) {
            $allowed[] = Role::SUPPLIER_USER;
        }

        // Neutral intermediary companies may invite intermediary users.
        if (! $company->isBuyer() && ! $company->isSupplier()) {
            $allowed[] = Role::INTERMEDIARY_USER;
        }

        if (! in_array($roleName, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => 'The selected role cannot be invited for this company.',
            ]);
        }

        if ($roleName === Role::ADMIN) {
            throw ValidationException::withMessages([
                'role' => 'The admin role cannot be invited.',
            ]);
        }

        return Role::query()->where('name', $roleName)->firstOrFail();
    }
}
