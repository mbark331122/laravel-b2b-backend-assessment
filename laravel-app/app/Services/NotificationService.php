<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\DeliveryConfirmation;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\ReturnShipment;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\Rma;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates and manages in-app notifications. Ownership and recipients are always server-derived.
 */
class NotificationService
{
    /**
     * @param  iterable<User|int>  $recipients
     * @param  array<string, mixed>  $metadata
     * @return list<Notification>
     */
    public function notifyCompanyUsers(
        int $companyId,
        iterable $recipients,
        string $type,
        string $title,
        string $body,
        ?Model $related = null,
        array $metadata = [],
        ?string $dedupeSuffix = null,
    ): array {
        $userIds = Collection::make($recipients)
            ->map(fn ($user) => $user instanceof User ? $user->id : (int) $user)
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $activeIds = User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('id', $userIds)
            ->pluck('id');

        $relatedType = $related ? class_basename($related) : null;
        $relatedId = $related?->getKey();
        $baseKey = $type.':'.($relatedType ?? 'none').':'.($relatedId ?? '0');
        if ($dedupeSuffix !== null) {
            $baseKey .= ':'.$dedupeSuffix;
        }

        $created = [];

        foreach ($activeIds as $userId) {
            $dedupeKey = $baseKey;

            try {
                $existing = Notification::query()
                    ->where('user_id', $userId)
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                $notification = new Notification;
                $notification->user_id = $userId;
                $notification->company_id = $companyId;
                $notification->type = $type;
                $notification->title = $title;
                $notification->body = $body;
                $notification->related_type = $relatedType;
                $notification->related_id = $relatedId;
                $notification->metadata = $this->safeMetadata($metadata);
                $notification->dedupe_key = $dedupeKey;
                $notification->save();

                $created[] = $notification;
            } catch (Throwable) {
                // Unique race: treat as already delivered.
            }
        }

        return $created;
    }

    /**
     * Active members of a company (server-derived recipients).
     *
     * @return Collection<int, User>
     */
    public function activeUsersForCompany(int $companyId, ?string $roleName = null): Collection
    {
        $query = User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        if ($roleName !== null) {
            $query->whereHas('role', fn ($q) => $q->where('name', $roleName));
        }

        return $query->get();
    }

    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at !== null) {
            return $notification;
        }

        return DB::transaction(function () use ($notification) {
            /** @var Notification $locked */
            $locked = Notification::query()->whereKey($notification->id)->lockForUpdate()->firstOrFail();

            if ($locked->read_at === null) {
                $locked->read_at = now();
                $locked->save();
            }

            return $locked->fresh();
        });
    }

    public function markAllReadForUser(User $user): int
    {
        return DB::transaction(function () use ($user) {
            return Notification::query()
                ->where('user_id', $user->id)
                ->where('company_id', $user->company_id)
                ->whereNull('read_at')
                ->lockForUpdate()
                ->update(['read_at' => now()]);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        // Strip fields that could leak Assessment 2 identities or spoofed ownership.
        unset(
            $metadata['buyer_company_id'],
            $metadata['supplier_company_id'],
            $metadata['buyer_company'],
            $metadata['supplier_company'],
            $metadata['company_name'],
            $metadata['party_identity'],
            $metadata['commission'],
            $metadata['confidential'],
            $metadata['recipient_id'],
            $metadata['user_id'],
            $metadata['company_id'],
        );

        return $metadata;
    }
}
