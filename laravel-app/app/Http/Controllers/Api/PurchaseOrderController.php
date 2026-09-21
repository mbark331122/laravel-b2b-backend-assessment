<?php

namespace App\Http\Controllers\Api;

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

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $user = $request->user();
        $visibility = app(TransactionVisibilityService::class);
        $query = PurchaseOrder::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->visibleTo($user)
            ->orderByDesc('id');

        // Buyers list their own POs; suppliers should use the supplier endpoint,
        // but visibleTo still scopes correctly if they hit this route.
        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        $orders = $query->get()
            ->map(fn (PurchaseOrder $po) => $visibility->serializePurchaseOrder($user, $po))
            ->values();

        return response()->json([
            'purchase_orders' => $orders,
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $visibility = app(TransactionVisibilityService::class);

        $orders = PurchaseOrder::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->where('supplier_company_id', $user->company_id)
            ->whereIn('status', [
                PurchaseOrder::STATUS_PENDING_SUPPLIER_CONFIRMATION,
                PurchaseOrder::STATUS_CONFIRMED,
                PurchaseOrder::STATUS_REJECTED,
                PurchaseOrder::STATUS_CANCELLED,
                PurchaseOrder::STATUS_COMPLETED,
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PurchaseOrder $po) => $visibility->serializePurchaseOrder($user, $po))
            ->values();

        return response()->json([
            'purchase_orders' => $orders,
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        Gate::authorize('view', $purchaseOrder);

        $purchaseOrder->load([
            'items',
            'buyerCompany',
            'supplierCompany.supplierProfile',
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
    ): JsonResponse {
        Gate::authorize('createFromNegotiation', [PurchaseOrder::class, $negotiation]);

        $result = $purchaseOrders->createFromAcceptedNegotiation($negotiation, $request->user());
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
            'purchase_order' => $po->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        Gate::authorize('submit', $purchaseOrder);

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
            'purchase_order' => $po->toApiArray(),
        ]);
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        Gate::authorize('cancel', $purchaseOrder);

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
            'purchase_order' => $po->toApiArray(),
        ]);
    }

    public function complete(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        Gate::authorize('complete', $purchaseOrder);

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
            'purchase_order' => $po->toApiArray(),
        ]);
    }

    public function confirm(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrders): JsonResponse
    {
        Gate::authorize('confirm', $purchaseOrder);

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
            'purchase_order' => $po->toApiArray(),
        ]);
    }

    public function reject(
        RejectPurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrders,
    ): JsonResponse {
        Gate::authorize('reject', $purchaseOrder);

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
            'purchase_order' => $po->toApiArray(),
        ]);
    }
}
