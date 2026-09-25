<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CompanyInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpiredByTime(?Carbon $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte($now ?? now());
    }

    /**
     * Mark pending invitations expired when past expires_at. Safe to call on reads.
     */
    public function refreshExpiration(): bool
    {
        if (! $this->isPending() || ! $this->isExpiredByTime()) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        $this->pending_lock = null;
        $this->save();

        return true;
    }

    public function matchesToken(string $plainToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $plainToken));
    }

    /**
     * @return array{invitation: CompanyInvitation, plain_token: string}
     */
    public static function issue(
        Company $company,
        string $email,
        Role $role,
        User $invitedBy,
        Carbon $expiresAt,
    ): array {
        $plain = Str::random(64);
        $invitation = new self;
        $invitation->company()->associate($company);
        $invitation->role()->associate($role);
        $invitation->email = Str::lower(trim($email));
        $invitation->token_hash = hash('sha256', $plain);
        $invitation->status = self::STATUS_PENDING;
        $invitation->invitedBy()->associate($invitedBy);
        $invitation->expires_at = $expiresAt;
        $invitation->pending_lock = self::pendingLockKey($company->id, $invitation->email);
        $invitation->save();

        return ['invitation' => $invitation, 'plain_token' => $plain];
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return self::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }

    public static function pendingLockKey(int $companyId, string $email): string
    {
        return $companyId.':'.Str::lower(trim($email));
    }

    /**
     * API serialization never includes token_hash or plain tokens.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing('role');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'email' => $this->email,
            'role' => $this->role?->name,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'invited_by_user_id' => $this->invited_by_user_id,
            'accepted_user_id' => $this->accepted_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
