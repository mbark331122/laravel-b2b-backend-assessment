<?php

namespace App\Services;

use App\Models\IntermediaryCommission;
use App\Models\PartyIdentityGrant;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfidentialNote;
use App\Models\PurchaseOrderParty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Central multi-party confidentiality authority for purchase-order transactions.
 *
 * Visibility categories (conceptual):
 * - PUBLIC_TO_TRANSACTION: role labels, PO number/status/currency (no identities)
 * - PARTY_ONLY: own party identity + own commission
 * - AUTHORIZED_PARTIES: identities only with explicit grants (+ permission)
 * - INTERNAL_ONLY / CONFIDENTIAL: notes and cross-party commissions for privileged internals
 */
class TransactionVisibilityService
{
    public const CATEGORY_PUBLIC_TO_TRANSACTION = 'PUBLIC_TO_TRANSACTION';

    public const CATEGORY_PARTY_ONLY = 'PARTY_ONLY';

    public const CATEGORY_AUTHORIZED_PARTIES = 'AUTHORIZED_PARTIES';

    public const CATEGORY_INTERNAL_ONLY = 'INTERNAL_ONLY';

    public const CATEGORY_CONFIDENTIAL = 'CONFIDENTIAL';

    /**
     * Ensure buyer/supplier party rows exist for a PO.
     * Mutual identity grants are applied only when both core parties are newly created
     * and $grantMutualIdentity is true (classic direct trade). Later calls never escalate visibility.
     *
     * @param  bool  $lock  When false (read/serialize path), skip row locks if parties already exist.
     * @return array{buyer: PurchaseOrderParty, supplier: PurchaseOrderParty}
     */
    public function ensureCoreParties(
        PurchaseOrder $purchaseOrder,
        bool $grantMutualIdentity = false,
        bool $lock = true,
    ): array {
        if (! $lock) {
            $existing = $this->existingCoreParties($purchaseOrder);
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($purchaseOrder, $grantMutualIdentity, $lock) {
            /** @var PurchaseOrder $locked */
            $query = PurchaseOrder::query()->whereKey($purchaseOrder->id);
            $locked = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

            [$buyer, $buyerCreated] = $this->findOrCreateParty(
                $locked,
                (int) $locked->buyer_company_id,
                PurchaseOrderParty::ROLE_BUYER,
                0,
                $lock,
            );
            [$supplier, $supplierCreated] = $this->findOrCreateParty(
                $locked,
                (int) $locked->supplier_company_id,
                PurchaseOrderParty::ROLE_SUPPLIER,
                0,
                $lock,
            );

            if ($grantMutualIdentity && $buyerCreated && $supplierCreated) {
                $this->grantIdentityInternal($locked, $buyer, $supplier, null);
                $this->grantIdentityInternal($locked, $supplier, $buyer, null);
            }

            return ['buyer' => $buyer, 'supplier' => $supplier];
        });
    }

    public function actorParty(PurchaseOrder $purchaseOrder, User $user): ?PurchaseOrderParty
    {
        if ($user->company_id === null) {
            return null;
        }

        if ($purchaseOrder->relationLoaded('parties')) {
            return $purchaseOrder->parties->first(
                fn (PurchaseOrderParty $party) => (int) $party->company_id === (int) $user->company_id
                    && $party->status === PurchaseOrderParty::STATUS_ACTIVE
            );
        }

        return PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('company_id', $user->company_id)
            ->where('status', PurchaseOrderParty::STATUS_ACTIVE)
            ->first();
    }

    public function canAccessTransaction(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if ($user->isAdmin()) {
            return $user->hasPermission(Permission::PURCHASE_ORDER_READ)
                || $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_READ);
        }

        if (! $user->hasPermission(Permission::PURCHASE_ORDER_READ)
            && ! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_READ)) {
            return false;
        }

        if ($user->company_id === null) {
            return false;
        }

        if ((int) $user->company_id === (int) $purchaseOrder->buyer_company_id
            || (int) $user->company_id === (int) $purchaseOrder->supplier_company_id) {
            return true;
        }

        return $this->actorParty($purchaseOrder, $user) !== null;
    }

    public function canViewPartyIdentity(User $user, PurchaseOrder $purchaseOrder, PurchaseOrderParty $target): bool
    {
        if (! $target->isActive() || (int) $target->purchase_order_id !== (int) $purchaseOrder->id) {
            return false;
        }

        if ($user->isAdmin() && $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_IDENTITY_READ)) {
            return true;
        }

        if (! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_IDENTITY_READ)) {
            return false;
        }

        $viewer = $this->actorParty($purchaseOrder, $user);
        if ($viewer === null) {
            return false;
        }

        if ((int) $viewer->id === (int) $target->id) {
            return true;
        }

        return PartyIdentityGrant::query()
            ->where('viewer_party_id', $viewer->id)
            ->where('visible_party_id', $target->id)
            ->exists();
    }

    public function canViewCommission(User $user, IntermediaryCommission $commission): bool
    {
        $commission->loadMissing('party', 'purchaseOrder');
        $purchaseOrder = $commission->purchaseOrder;
        $party = $commission->party;

        if ($purchaseOrder === null || $party === null) {
            return false;
        }

        if (! $this->canAccessTransaction($user, $purchaseOrder)) {
            return false;
        }

        if (! $user->hasPermission(Permission::PURCHASE_ORDER_COMMISSION_READ)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $viewer = $this->actorParty($purchaseOrder, $user);
        if ($viewer === null) {
            return false;
        }

        // Own commission only for non-admin actors.
        return (int) $viewer->id === (int) $party->id;
    }

    public function canViewConfidential(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $this->canAccessTransaction($user, $purchaseOrder)) {
            return false;
        }

        return $user->hasPermission(Permission::PURCHASE_ORDER_CONFIDENTIAL_READ);
    }

    public function canManageParties(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_PARTY_MANAGE)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        // Buyer company operators manage multi-party setup on their POs.
        return $user->company_id !== null
            && (int) $user->company_id === (int) $purchaseOrder->buyer_company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePurchaseOrder(User $user, PurchaseOrder $purchaseOrder): array
    {
        // Read path: avoid lockForUpdate when core parties already exist.
        $this->ensureCoreParties($purchaseOrder, grantMutualIdentity: false, lock: false);

        $base = [
            'id' => $purchaseOrder->id,
            'number' => $purchaseOrder->number,
            'status' => $purchaseOrder->status,
            'currency' => $purchaseOrder->currency,
            'shipping_amount' => (string) $purchaseOrder->shipping_amount,
            'tax_amount' => (string) $purchaseOrder->tax_amount,
            'subtotal' => (string) $purchaseOrder->subtotal,
            'total' => (string) $purchaseOrder->total,
            'notes' => $purchaseOrder->notes,
            'visibility_category' => self::CATEGORY_PUBLIC_TO_TRANSACTION,
        ];

        $buyerParty = $this->resolveCoreParty($purchaseOrder, PurchaseOrderParty::ROLE_BUYER);
        $supplierParty = $this->resolveCoreParty($purchaseOrder, PurchaseOrderParty::ROLE_SUPPLIER);

        $base['buyer_company'] = ($buyerParty && $this->canViewPartyIdentity($user, $purchaseOrder, $buyerParty))
            ? ['id' => $purchaseOrder->buyer_company_id, 'name' => $purchaseOrder->buyerCompany?->name]
            : null;

        $base['supplier_company'] = ($supplierParty && $this->canViewPartyIdentity($user, $purchaseOrder, $supplierParty))
            ? [
                'id' => $purchaseOrder->supplier_company_id,
                'name' => $purchaseOrder->supplierCompany?->name,
                'display_name' => $purchaseOrder->supplierCompany?->supplierProfile?->display_name,
            ]
            : null;

        // Preserve negotiation linkage only for classic parties that already know the commercial chain.
        $viewer = $this->actorParty($purchaseOrder, $user);
        $isCoreParty = $viewer && in_array($viewer->role, [
            PurchaseOrderParty::ROLE_BUYER,
            PurchaseOrderParty::ROLE_SUPPLIER,
        ], true);

        if ($user->isAdmin() || $isCoreParty) {
            $base['negotiation_id'] = $purchaseOrder->negotiation_id;
            $base['accepted_offer_id'] = $purchaseOrder->accepted_offer_id;
            $base['quotation_id'] = $purchaseOrder->quotation_id;
            $base['rfq_id'] = $purchaseOrder->rfq_id;
            $base['rfq_distribution_id'] = $purchaseOrder->rfq_distribution_id;
            $base['rejection_reason'] = $purchaseOrder->rejection_reason;
            $purchaseOrder->loadMissing('items');
            $base['items'] = $purchaseOrder->items->map(fn ($item) => $item->toApiArray())->values()->all();
            $base['submitted_at'] = $purchaseOrder->submitted_at?->toISOString();
            $base['confirmed_at'] = $purchaseOrder->confirmed_at?->toISOString();
            $base['rejected_at'] = $purchaseOrder->rejected_at?->toISOString();
            $base['cancelled_at'] = $purchaseOrder->cancelled_at?->toISOString();
            $base['completed_at'] = $purchaseOrder->completed_at?->toISOString();
            $base['created_at'] = $purchaseOrder->created_at?->toISOString();
            $base['updated_at'] = $purchaseOrder->updated_at?->toISOString();
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeParty(User $user, PurchaseOrder $purchaseOrder, PurchaseOrderParty $party, bool $auditSensitive = false): array
    {
        $payload = [
            'id' => $party->id,
            'purchase_order_id' => $party->purchase_order_id,
            'role' => $party->role,
            'status' => $party->status,
            'sequence' => $party->sequence,
            'joined_at' => $party->joined_at?->toISOString(),
            'company' => null,
            'visibility_category' => self::CATEGORY_PUBLIC_TO_TRANSACTION,
        ];

        if ($this->canViewPartyIdentity($user, $purchaseOrder, $party)) {
            $party->loadMissing('company');
            $payload['company'] = [
                'id' => $party->company_id,
                'name' => $party->company?->name,
            ];
            $payload['visibility_category'] = self::CATEGORY_AUTHORIZED_PARTIES;

            if ($auditSensitive) {
                app(AuditLogger::class)->record(
                    \App\Models\AuditLog::PARTY_IDENTITY_VIEWED,
                    $party,
                    $user->company_id,
                    after: [
                        'purchase_order_id' => $purchaseOrder->id,
                        'party_id' => $party->id,
                        'role' => $party->role,
                    ],
                );
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serializeCommission(User $user, IntermediaryCommission $commission, bool $auditSensitive = false): ?array
    {
        if (! $this->canViewCommission($user, $commission)) {
            return null;
        }

        $payload = [
            'id' => $commission->id,
            'purchase_order_id' => $commission->purchase_order_id,
            'purchase_order_party_id' => $commission->purchase_order_party_id,
            'amount' => (string) $commission->amount,
            'currency' => $commission->currency,
            'notes' => $commission->notes,
            'visibility_category' => self::CATEGORY_PARTY_ONLY,
            'created_at' => $commission->created_at?->toISOString(),
            'updated_at' => $commission->updated_at?->toISOString(),
        ];

        if ($user->isAdmin() && $user->hasPermission(Permission::PURCHASE_ORDER_COMMISSION_READ)) {
            $payload['visibility_category'] = self::CATEGORY_CONFIDENTIAL;
        }

        if ($auditSensitive) {
            app(AuditLogger::class)->record(
                \App\Models\AuditLog::COMMISSION_VIEWED,
                $commission,
                $user->company_id,
                after: [
                    'purchase_order_id' => $commission->purchase_order_id,
                    'commission_id' => $commission->id,
                    'party_id' => $commission->purchase_order_party_id,
                ],
            );
        }

        return $payload;
    }

    /**
     * Authorized export representation — same rules as API serialization.
     *
     * @return array<string, mixed>
     */
    public function exportRepresentation(User $user, PurchaseOrder $purchaseOrder): array
    {
        $this->ensureCoreParties($purchaseOrder, grantMutualIdentity: false, lock: false);

        $parties = PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('status', PurchaseOrderParty::STATUS_ACTIVE)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseOrderParty $party) => $this->serializeParty($user, $purchaseOrder, $party))
            ->values()
            ->all();

        $commissions = IntermediaryCommission::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (IntermediaryCommission $c) => $this->serializeCommission($user, $c))
            ->filter()
            ->values()
            ->all();

        $notes = [];
        if ($this->canViewConfidential($user, $purchaseOrder)) {
            $notes = PurchaseOrderConfidentialNote::query()
                ->where('purchase_order_id', $purchaseOrder->id)
                ->orderBy('id')
                ->get()
                ->map(fn (PurchaseOrderConfidentialNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toISOString(),
                ])
                ->values()
                ->all();

            app(AuditLogger::class)->record(
                \App\Models\AuditLog::CONFIDENTIAL_VIEWED,
                $purchaseOrder,
                $user->company_id,
                after: ['purchase_order_id' => $purchaseOrder->id, 'via' => 'export'],
            );
        }

        return [
            'purchase_order' => $this->serializePurchaseOrder($user, $purchaseOrder),
            'parties' => $parties,
            'commissions' => $commissions,
            'confidential_notes' => $notes,
        ];
    }

    /**
     * Search parties by name without leaking hidden identities.
     *
     * @return list<array<string, mixed>>
     */
    public function searchParties(User $user, PurchaseOrder $purchaseOrder, ?string $query): array
    {
        $this->ensureCoreParties($purchaseOrder, grantMutualIdentity: false, lock: false);

        $parties = PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('status', PurchaseOrderParty::STATUS_ACTIVE)
            ->with('company')
            ->orderBy('id')
            ->get();

        $needle = $query !== null ? mb_strtolower(trim($query)) : '';

        $results = [];
        foreach ($parties as $party) {
            $canSee = $this->canViewPartyIdentity($user, $purchaseOrder, $party);
            if ($needle !== '') {
                if (! $canSee) {
                    // Hidden parties are not discoverable by name/id search.
                    continue;
                }
                $hay = mb_strtolower(($party->company?->name ?? '').' '.(string) $party->company_id);
                if (! str_contains($hay, $needle)) {
                    continue;
                }
            }

            $results[] = $this->serializeParty($user, $purchaseOrder, $party);
        }

        return $results;
    }

    public function addIntermediary(
        PurchaseOrder $purchaseOrder,
        int $companyId,
        User $actor,
        ?int $sequence = null,
    ): PurchaseOrderParty {
        return DB::transaction(function () use ($purchaseOrder, $companyId, $actor, $sequence) {
            $this->ensureCoreParties($purchaseOrder);

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if ((int) $companyId === (int) $locked->buyer_company_id
                || (int) $companyId === (int) $locked->supplier_company_id) {
                throw ValidationException::withMessages([
                    'company_id' => 'Buyer and supplier companies cannot be added as intermediaries.',
                ]);
            }

            $existing = PurchaseOrderParty::query()
                ->where('purchase_order_id', $locked->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === PurchaseOrderParty::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'company_id' => 'This company already participates in the purchase order.',
                ]);
            }

            $nextSequence = $sequence ?? ((int) PurchaseOrderParty::query()
                ->where('purchase_order_id', $locked->id)
                ->where('role', PurchaseOrderParty::ROLE_INTERMEDIARY)
                ->max('sequence') + 1);

            if ($existing) {
                $existing->role = PurchaseOrderParty::ROLE_INTERMEDIARY;
                $existing->status = PurchaseOrderParty::STATUS_ACTIVE;
                $existing->sequence = $nextSequence;
                $existing->joined_at = now();
                $existing->save();
                $party = $existing;
            } else {
                [$party] = $this->findOrCreateParty(
                    $locked,
                    $companyId,
                    PurchaseOrderParty::ROLE_INTERMEDIARY,
                    $nextSequence,
                );
            }

            return $party->fresh(['company']);
        });
    }

    public function grantIdentity(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderParty $viewer,
        PurchaseOrderParty $visible,
        User $actor,
    ): PartyIdentityGrant {
        if ((int) $viewer->purchase_order_id !== (int) $purchaseOrder->id
            || (int) $visible->purchase_order_id !== (int) $purchaseOrder->id) {
            throw ValidationException::withMessages([
                'party' => 'Identity grants must reference parties on the same purchase order.',
            ]);
        }

        if ((int) $viewer->id === (int) $visible->id) {
            throw ValidationException::withMessages([
                'party' => 'A party already knows its own identity.',
            ]);
        }

        return $this->grantIdentityInternal($purchaseOrder, $viewer, $visible, $actor);
    }

    public function revokeIdentity(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderParty $viewer,
        PurchaseOrderParty $visible,
    ): void {
        PartyIdentityGrant::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('viewer_party_id', $viewer->id)
            ->where('visible_party_id', $visible->id)
            ->delete();
    }

    public function setCommission(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderParty $party,
        string $amount,
        string $currency,
        ?string $notes = null,
    ): IntermediaryCommission {
        if ((int) $party->purchase_order_id !== (int) $purchaseOrder->id) {
            throw ValidationException::withMessages([
                'party' => 'Commission party must belong to the purchase order.',
            ]);
        }

        if ($party->role !== PurchaseOrderParty::ROLE_INTERMEDIARY) {
            throw ValidationException::withMessages([
                'party' => 'Commissions may only be assigned to intermediary parties.',
            ]);
        }

        if (bccomp($amount, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'Commission amount must be zero or greater.',
            ]);
        }

        return DB::transaction(function () use ($purchaseOrder, $party, $amount, $currency, $notes) {
            $commission = IntermediaryCommission::query()
                ->where('purchase_order_party_id', $party->id)
                ->lockForUpdate()
                ->first();

            if (! $commission) {
                $commission = new IntermediaryCommission;
                $commission->purchase_order_id = $purchaseOrder->id;
                $commission->purchase_order_party_id = $party->id;
            }

            $commission->amount = $amount;
            $commission->currency = strtoupper($currency);
            $commission->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $commission->save();

            return $commission->fresh(['party']);
        });
    }

    public function addConfidentialNote(PurchaseOrder $purchaseOrder, User $actor, string $body): PurchaseOrderConfidentialNote
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Confidential note body is required.',
            ]);
        }

        $note = new PurchaseOrderConfidentialNote;
        $note->purchase_order_id = $purchaseOrder->id;
        $note->created_by_user_id = $actor->id;
        $note->body = $body;
        $note->save();

        return $note;
    }

    /**
     * Commissions visible to the actor (query-level filter).
     *
     * @return Collection<int, IntermediaryCommission>
     */
    public function visibleCommissions(User $user, PurchaseOrder $purchaseOrder): Collection
    {
        if (! $user->hasPermission(Permission::PURCHASE_ORDER_COMMISSION_READ)) {
            return collect();
        }

        $query = IntermediaryCommission::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderBy('id');

        if ($user->isAdmin()) {
            return $query->get();
        }

        $viewer = $this->actorParty($purchaseOrder, $user);
        if ($viewer === null) {
            return collect();
        }

        return $query->where('purchase_order_party_id', $viewer->id)->get();
    }

    /**
     * @return array{buyer: PurchaseOrderParty, supplier: PurchaseOrderParty}|null
     */
    private function existingCoreParties(PurchaseOrder $purchaseOrder): ?array
    {
        $buyer = $this->resolveCoreParty($purchaseOrder, PurchaseOrderParty::ROLE_BUYER);
        $supplier = $this->resolveCoreParty($purchaseOrder, PurchaseOrderParty::ROLE_SUPPLIER);

        if ($buyer === null || $supplier === null) {
            return null;
        }

        return ['buyer' => $buyer, 'supplier' => $supplier];
    }

    private function resolveCoreParty(PurchaseOrder $purchaseOrder, string $role): ?PurchaseOrderParty
    {
        if ($purchaseOrder->relationLoaded('parties')) {
            return $purchaseOrder->parties->first(
                fn (PurchaseOrderParty $party) => $party->role === $role
                    && $party->status === PurchaseOrderParty::STATUS_ACTIVE
            );
        }

        return PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('role', $role)
            ->where('status', PurchaseOrderParty::STATUS_ACTIVE)
            ->first();
    }

    /**
     * @return array{0: PurchaseOrderParty, 1: bool}
     */
    private function findOrCreateParty(
        PurchaseOrder $purchaseOrder,
        int $companyId,
        string $role,
        int $sequence,
        bool $lock = true,
    ): array {
        $query = PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('company_id', $companyId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $party = $query->first();

        if ($party) {
            if ($party->status !== PurchaseOrderParty::STATUS_ACTIVE) {
                $party->status = PurchaseOrderParty::STATUS_ACTIVE;
                $party->role = $role;
                $party->sequence = $sequence;
                $party->joined_at = now();
                $party->save();
            }

            return [$party, false];
        }

        $party = new PurchaseOrderParty;
        $party->purchase_order_id = $purchaseOrder->id;
        $party->company_id = $companyId;
        $party->role = $role;
        $party->status = PurchaseOrderParty::STATUS_ACTIVE;
        $party->sequence = $sequence;
        $party->joined_at = now();
        $party->save();

        return [$party, true];
    }

    private function grantIdentityInternal(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderParty $viewer,
        PurchaseOrderParty $visible,
        ?User $actor,
    ): PartyIdentityGrant {
        $grant = PartyIdentityGrant::query()
            ->where('viewer_party_id', $viewer->id)
            ->where('visible_party_id', $visible->id)
            ->first();

        if ($grant) {
            return $grant;
        }

        $grant = new PartyIdentityGrant;
        $grant->purchase_order_id = $purchaseOrder->id;
        $grant->viewer_party_id = $viewer->id;
        $grant->visible_party_id = $visible->id;
        $grant->granted_by_user_id = $actor?->id;
        $grant->granted_at = now();
        $grant->save();

        return $grant;
    }
}
