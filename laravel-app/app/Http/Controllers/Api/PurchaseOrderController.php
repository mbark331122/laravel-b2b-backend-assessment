<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectPurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Models\AuditLog;
use App\Models\Negotiation;
use App\Models\PurchaseOrder;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderService;
use App\Services\TransactionVisibilityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseOrderController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $user = $request->user();
        $visibility = app(TransactionVisibilityService::class);
        $query = PurchaseOrder::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile', 'parties.company'])
            ->visibleTo($user)
            ->orderByDesc('id');

        // Buyers list their own POs; suppliers should use the supplier endpoint,
        // but visibleTo still scopes correctly if they hit this route.
        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'purchase_orders',
            fn (PurchaseOrder $po) => $visibility->serializePurchaseOrder($user, $po),
        );
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $visibility = app(TransactionVisibilityService::class);

        $query = PurchaseOrder::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile', 'parties.company'])
            ->where('supplier_company_id', $user->company_id)
            ->whereIn('status', [
                PurchaseOrder::STATUS_PENDING_SUPPLIER_CONFIRMATION,
                PurchaseOrder::STATUS_CONFIRMED,
                PurchaseOrder::STATUS_REJECTED,
                PurchaseOrder::STATUS_CANCELLED,
                PurchaseOrder::STATUS_COMPLETED,
            ])
            ->orderByDesc('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'purchase_orders',
            fn (PurchaseOrder $po) => $visibility->serializePurchaseOrder($user, $po),
        );
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        Gate::authorize('view', $purchaseOrder);

        $purchaseOrder->load([
            'items',
            'buyerCompany',
            'supplierCompany.supplierProfile',
            'parties.company',
        ]);

        return response()->json([
            'purchase_order' => app(TransactionVisibilityService::class)
                ->serializePurchaseOrder($request->user(), $purchaseOrder),
        ]);
    }

    public function store(
        StorePurchaseOrderRequest $request,
        Negotiation $negotiation,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('createFromNegotiation', [PurchaseOrder::class, $negotiation]);

        $user = $request->user();
        $result = $purchaseOrders->createFromAcceptedNegotiation($negotiation, $user);
        $po = $result['purchase_order'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::PURCHASE_ORDER_CREATED,
                $po,
                $po->buyer_company_id,
                after: $po->toApiArray(),
            );
        }

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ], $result['created'] ? 201 : 200);
    }

    public function submit(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('submit', $purchaseOrder);

        $user = $request->user();
        $before = $purchaseOrder->toApiArray();
        $po = $purchaseOrders->submit($purchaseOrder);

        app(AuditLogger::class)->record(
            AuditLog::PURCHASE_ORDER_SUBMITTED,
            $po,
            $po->buyer_company_id,
            before: $before,
            after: $po->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ]);
    }

    public function cancel(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('cancel', $purchaseOrder);

        $user = $request->user();
        $before = $purchaseOrder->toApiArray();
        $po = $purchaseOrders->cancel($purchaseOrder);

        app(AuditLogger::class)->record(
            AuditLog::PURCHASE_ORDER_CANCELLED,
            $po,
            $po->buyer_company_id,
            before: $before,
            after: $po->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ]);
    }

    public function complete(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('complete', $purchaseOrder);

        $user = $request->user();
        $before = $purchaseOrder->toApiArray();
        $po = $purchaseOrders->complete($purchaseOrder);

        app(AuditLogger::class)->record(
            AuditLog::PURCHASE_ORDER_COMPLETED,
            $po,
            $po->buyer_company_id,
            before: $before,
            after: $po->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ]);
    }

    public function confirm(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('confirm', $purchaseOrder);

        $user = $request->user();
        $before = $purchaseOrder->toApiArray();
        $po = $purchaseOrders->confirm($purchaseOrder);

        app(AuditLogger::class)->record(
            AuditLog::PURCHASE_ORDER_CONFIRMED,
            $po,
            $po->supplier_company_id,
            before: $before,
            after: $po->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ]);
    }

    public function reject(
        RejectPurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
        TransactionVisibilityService $visibility,
    ): JsonResponse {
        Gate::authorize('reject', $purchaseOrder);

        $user = $request->user();
        $before = $purchaseOrder->toApiArray();
        $po = $purchaseOrders->reject($purchaseOrder, (string) $request->validated('reason'));

        app(AuditLogger::class)->record(
            AuditLog::PURCHASE_ORDER_REJECTED,
            $po,
            $po->supplier_company_id,
            before: $before,
            after: $po->toApiArray(),
            reason: $request->validated('reason'),
        );

        return response()->json([
            'purchase_order' => $visibility->serializePurchaseOrder($user, $po),
        ]);
    }
}
