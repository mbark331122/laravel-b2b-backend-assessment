<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active membership is server-managed (never mass-assigned from client input).
     */
    public function isActiveMember(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Company is stored on the user record and is never taken from client input.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /**
     * Buyer capability is resolved from the user's authorized company, never from client input.
     */
    public function isBuyerUser(): bool
    {
        return $this->company?->isBuyer() === true;
    }

    /**
     * Supplier capability is resolved from the user's authorized company, never from client input.
     */
    public function isSupplierUser(): bool
    {
        return $this->company?->isSupplier() === true;
    }

    public function isIntermediaryUser(): bool
    {
        return $this->hasRole(Role::INTERMEDIARY_USER);
    }

    public function hasRole(string $role): bool
    {
        return $this->role?->name === $role;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->role?->permissions->pluck('name')->values()->all() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['company', 'role.permissions']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->isActiveMember(),
            'is_admin' => $this->isAdmin(),
            'company' => $this->company?->toApiArray(),
            'role' => $this->role?->name,
            'permissions' => $this->permissionNames(),
        ];
    }

    /**
     * Member listing for company administrators (no permission dump).
     *
     * @return array<string, mixed>
     */
    public function toMemberApiArray(): array
    {
        $this->loadMissing('role');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->isActiveMember(),
            'role' => $this->role?->name,
        ];
    }
}
