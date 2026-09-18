<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentRequest;
use App\Http\Requests\UpdateShipmentRequest;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Services\AuditLogger;
use App\Services\ShipmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShipmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Shipment::class);

        $user = $request->user();
        $query = Shipment::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile', 'purchaseOrder'])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return response()->json([
            'shipments' => $query->get()->map(fn (Shipment $shipment) => $shipment->toApiArray())->values(),
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Shipment::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $shipments = Shipment::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile', 'purchaseOrder'])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Shipment $shipment) => $shipment->toApiArray())
            ->values();

        return response()->json([
            'shipments' => $shipments,
        ]);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        Gate::authorize('view', $shipment);

        return response()->json([
            'shipment' => $shipment->load([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'purchaseOrder',
            ])->toApiArray(),
        ]);
    }

    public function showForPurchaseOrder(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->firstOrFail();

        Gate::authorize('view', $shipment);

        return response()->json([
            'shipment' => $shipment->load([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'purchaseOrder',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreShipmentRequest $request,
        PurchaseOrder $purchaseOrder,
        ShipmentService $shipments,
    ): JsonResponse {
        Gate::authorize('createFromPurchaseOrder', [Shipment::class, $purchaseOrder]);

        $result = $shipments->createFromConfirmedPurchaseOrder(
            $purchaseOrder,
            $request->user(),
            $request->validated(),
        );
        $shipment = $result['shipment'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::SHIPMENT_CREATED,
                $shipment,
                $shipment->supplier_company_id,
                after: $shipment->toApiArray(),
            );
        }

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function update(
        UpdateShipmentRequest $request,
        Shipment $shipment,
        ShipmentService $shipments,
    ): JsonResponse {
        Gate::authorize('update', $shipment);

        $before = $shipment->toApiArray();
        $shipment = $shipments->updateOperational($shipment, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::SHIPMENT_UPDATED,
            $shipment,
            $shipment->supplier_company_id,
            before: $before,
            after: $shipment->toApiArray(),
        );

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ]);
    }

    public function processing(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::authorize('process', $shipment);

        $before = $shipment->toApiArray();
        $shipment = $shipments->markProcessing($shipment);

        app(AuditLogger::class)->record(
            AuditLog::SHIPMENT_PROCESSING,
            $shipment,
            $shipment->supplier_company_id,
            before: $before,
            after: $shipment->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ]);
    }

    public function ship(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::authorize('ship', $shipment);

        $before = $shipment->toApiArray();
        $shipment = $shipments->markShipped($shipment);

        app(AuditLogger::class)->record(
            AuditLog::SHIPMENT_SHIPPED,
            $shipment,
            $shipment->supplier_company_id,
            before: $before,
            after: $shipment->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ]);
    }

    public function deliver(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::authorize('deliver', $shipment);

        $before = $shipment->toApiArray();
        $shipment = $shipments->markDelivered($shipment);

        app(AuditLogger::class)->record(
            AuditLog::SHIPMENT_DELIVERED,
            $shipment,
            $shipment->supplier_company_id,
            before: $before,
            after: $shipment->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ]);
    }

    public function cancel(Request $request, Shipment $shipment, ShipmentService $shipments): JsonResponse
    {
        Gate::authorize('cancel', $shipment);

        $before = $shipment->toApiArray();
        $shipment = $shipments->cancel($shipment);

        app(AuditLogger::class)->record(
            AuditLog::SHIPMENT_CANCELLED,
            $shipment,
            $shipment->supplier_company_id,
            before: $before,
            after: $shipment->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'shipment' => $shipment->toApiArray(),
        ]);
    }
}
