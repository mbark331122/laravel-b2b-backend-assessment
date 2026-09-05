<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Record a state-changing action. Actor comes from auth, never from the client.
     * Company comes from the authorized resource, never from a request company_id.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        Model $auditable,
        ?int $companyId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $actor = auth()->user();

        AuditLog::query()->create([
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'company_id' => $companyId,
            'action' => $action,
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'before_value' => $before,
            'after_value' => $after,
            'reason' => $reason,
        ]);
    }
}
