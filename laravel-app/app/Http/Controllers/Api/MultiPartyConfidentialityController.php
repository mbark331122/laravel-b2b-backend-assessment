<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConfidentialNoteRequest;
use App\Http\Requests\StoreIntermediaryCommissionRequest;
use App\Http\Requests\StorePartyIdentityGrantRequest;
use App\Http\Requests\StorePurchaseOrderPartyRequest;
use App\Models\AuditLog;
use App\Models\IntermediaryCommission;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfidentialNote;
use App\Models\PurchaseOrderParty;
use App\Services\AuditLogger;
use App\Services\TransactionVisibilityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MultiPartyConfidentialityController extends Controller
{
    use AuthorizesRequests;

    public function parties(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('viewAny', [PurchaseOrderParty::class, $purchaseOrder]);

        // Ignore include/confidential query probes — never expand payload from client hints.
        $visibility->ensureCoreParties($purchaseOrder);

        $parties = PurchaseOrderParty::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('status', PurchaseOrderParty::STATUS_ACTIVE)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseOrderParty $party) => $visibility->serializeParty(
                $request->user(),
                $purchaseOrder,
                $party,
            ))
            ->values();

        return response()->json([
            'parties' => $parties,
        ]);
    }

    public function showParty(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderParty $party,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        $this->assertPartyBelongs($purchaseOrder, $party);
        Gate::authorize('view', $party);

        return response()->json([
            'party' => $visibility->serializeParty(
                $request->user(),
                $purchaseOrder,
                $party,
                auditSensitive: true,
            ),
        ]);
    }

    public function storeParty(
        StorePurchaseOrderPartyRequest $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('manage', [PurchaseOrderParty::class, $purchaseOrder]);

        $party = $visibility->addIntermediary(
            $purchaseOrder,
            (int) $request->validated('company_id'),
            $request->user(),
            isset($request->validated()['sequence']) ? (int) $request->validated('sequence') : null,
        );

        app(AuditLogger::class)->record(
            AuditLog::PARTY_ADDED,
            $party,
            $request->user()->company_id,
            after: [
                'purchase_order_id' => $purchaseOrder->id,
                'party_id' => $party->id,
                'role' => $party->role,
                'company_id' => $party->company_id,
            ],
        );

        return response()->json([
            'party' => $visibility->serializeParty($request->user(), $purchaseOrder, $party),
        ], 201);
    }

    public function grantIdentity(
        StorePartyIdentityGrantRequest $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('manage', [PurchaseOrderParty::class, $purchaseOrder]);

        $viewer = PurchaseOrderParty::query()->whereKey((int) $request->validated('viewer_party_id'))->firstOrFail();
        $visible = PurchaseOrderParty::query()->whereKey((int) $request->validated('visible_party_id'))->firstOrFail();
        $this->assertPartyBelongs($purchaseOrder, $viewer);
        $this->assertPartyBelongs($purchaseOrder, $visible);

        $grant = $visibility->grantIdentity($purchaseOrder, $viewer, $visible, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::PARTY_IDENTITY_GRANTED,
            $grant,
            $request->user()->company_id,
            after: [
                'purchase_order_id' => $purchaseOrder->id,
                'viewer_party_id' => $viewer->id,
                'visible_party_id' => $visible->id,
            ],
        );

        return response()->json([
            'grant' => [
                'id' => $grant->id,
                'viewer_party_id' => $grant->viewer_party_id,
                'visible_party_id' => $grant->visible_party_id,
                'granted_at' => $grant->granted_at?->toISOString(),
            ],
        ], 201);
    }

    public function revokeIdentity(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('manage', [PurchaseOrderParty::class, $purchaseOrder]);

        $viewerId = (int) $request->input('viewer_party_id');
        $visibleId = (int) $request->input('visible_party_id');
        if ($viewerId < 1 || $visibleId < 1) {
            throw ValidationException::withMessages([
                'viewer_party_id' => 'viewer_party_id and visible_party_id are required.',
            ]);
        }

        $viewer = PurchaseOrderParty::query()->whereKey($viewerId)->firstOrFail();
        $visible = PurchaseOrderParty::query()->whereKey($visibleId)->firstOrFail();
        $this->assertPartyBelongs($purchaseOrder, $viewer);
        $this->assertPartyBelongs($purchaseOrder, $visible);

        $visibility->revokeIdentity($purchaseOrder, $viewer, $visible);

        app(AuditLogger::class)->record(
            AuditLog::PARTY_IDENTITY_REVOKED,
            $purchaseOrder,
            $request->user()->company_id,
            after: [
                'purchase_order_id' => $purchaseOrder->id,
                'viewer_party_id' => $viewerId,
                'visible_party_id' => $visibleId,
            ],
        );

        return response()->json([
            'revoked' => true,
        ]);
    }

    public function commissions(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('view', $purchaseOrder);

        if (! $request->user()->hasPermission(Permission::PURCHASE_ORDER_COMMISSION_READ)) {
            abort(403);
        }

        // Client include/filter probes cannot expand visibility.
        $rows = $visibility->visibleCommissions($request->user(), $purchaseOrder)
            ->map(fn (IntermediaryCommission $c) => $visibility->serializeCommission($request->user(), $c))
            ->filter()
            ->values();

        return response()->json([
            'commissions' => $rows,
        ]);
    }

    public function showCommission(
        Request $request,
        IntermediaryCommission $commission,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('view', $commission);

        $payload = $visibility->serializeCommission($request->user(), $commission, auditSensitive: true);
        if ($payload === null) {
            abort(404);
        }

        return response()->json([
            'commission' => $payload,
        ]);
    }

    public function storeCommission(
        StoreIntermediaryCommissionRequest $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('manage', [PurchaseOrderParty::class, $purchaseOrder]);

        $party = PurchaseOrderParty::query()
            ->whereKey((int) $request->validated('purchase_order_party_id'))
            ->firstOrFail();
        $this->assertPartyBelongs($purchaseOrder, $party);

        $commission = $visibility->setCommission(
            $purchaseOrder,
            $party,
            (string) $request->validated('amount'),
            (string) $request->validated('currency'),
            $request->validated('notes'),
        );

        app(AuditLogger::class)->record(
            AuditLog::COMMISSION_SET,
            $commission,
            $request->user()->company_id,
            after: [
                'purchase_order_id' => $purchaseOrder->id,
                'commission_id' => $commission->id,
                'party_id' => $party->id,
            ],
        );

        // Write acknowledgment for authorized managers (they supplied the amount).
        // Subsequent GET/list still require commission.read + ownership rules.
        $serialized = $visibility->serializeCommission($request->user(), $commission);
        if ($serialized === null) {
            $serialized = [
                'id' => $commission->id,
                'purchase_order_id' => $commission->purchase_order_id,
                'purchase_order_party_id' => $commission->purchase_order_party_id,
                'amount' => (string) $commission->amount,
                'currency' => $commission->currency,
                'visibility_category' => TransactionVisibilityService::CATEGORY_PARTY_ONLY,
            ];
        }

        return response()->json([
            'commission' => $serialized,
        ], 201);
    }

    public function confidentialNotes(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('view', $purchaseOrder);

        if (! $visibility->canViewConfidential($request->user(), $purchaseOrder)) {
            abort(403);
        }

        $notes = PurchaseOrderConfidentialNote::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseOrderConfidentialNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at?->toISOString(),
            ])
            ->values();

        app(AuditLogger::class)->record(
            AuditLog::CONFIDENTIAL_VIEWED,
            $purchaseOrder,
            $request->user()->company_id,
            after: ['purchase_order_id' => $purchaseOrder->id, 'via' => 'list'],
        );

        return response()->json([
            'confidential_notes' => $notes,
        ]);
    }

    public function storeConfidentialNote(
        StoreConfidentialNoteRequest $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('manage', [PurchaseOrderParty::class, $purchaseOrder]);

        if (! $request->user()->hasPermission(Permission::PURCHASE_ORDER_CONFIDENTIAL_READ)
            && ! $request->user()->isAdmin()) {
            // Creating confidential notes requires confidential permission (admin has all).
            if (! $request->user()->hasPermission(Permission::PURCHASE_ORDER_PARTY_MANAGE)) {
                abort(403);
            }
        }

        // Only admins (or users with confidential.read) may create confidential notes.
        if (! $request->user()->hasPermission(Permission::PURCHASE_ORDER_CONFIDENTIAL_READ)) {
            abort(403);
        }

        $note = $visibility->addConfidentialNote(
            $purchaseOrder,
            $request->user(),
            (string) $request->validated('body'),
        );

        app(AuditLogger::class)->record(
            AuditLog::CONFIDENTIAL_NOTE_CREATED,
            $note,
            $request->user()->company_id,
            after: ['purchase_order_id' => $purchaseOrder->id, 'note_id' => $note->id],
        );

        return response()->json([
            'confidential_note' => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function searchParties(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('viewAny', [PurchaseOrderParty::class, $purchaseOrder]);

        $results = $visibility->searchParties(
            $request->user(),
            $purchaseOrder,
            $request->query('q'),
        );

        return response()->json([
            'parties' => $results,
        ]);
    }

    public function exportView(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('view', $purchaseOrder);

        return response()->json(
            $visibility->exportRepresentation($request->user(), $purchaseOrder)
        );
    }

    public function showPurchaseOrderScoped(
        Request $request,
        PurchaseOrder $purchaseOrder,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('view', $purchaseOrder);

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($request->user(), $purchaseOrder),
        ]);
    }

    private function assertPartyBelongs(PurchaseOrder $purchaseOrder, PurchaseOrderParty $party): void
    {
        if ((int) $party->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }
    }
}
