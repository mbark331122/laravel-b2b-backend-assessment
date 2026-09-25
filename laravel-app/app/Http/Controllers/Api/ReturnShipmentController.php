<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReturnShipmentRequest;
use App\Http\Requests\UpdateReturnShipmentRequest;
use App\Models\AuditLog;
use App\Models\ReturnShipment;
use App\Models\Rma;
use App\Services\AuditLogger;
use App\Services\DomainNotificationPublisher;
use App\Services\ReturnShipmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReturnShipmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ReturnShipment::class);

        $user = $request->user();
        $query = ReturnShipment::query()
            ->with([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return response()->json([
            'return_shipments' => $query->get()
                ->map(fn (ReturnShipment $returnShipment) => $returnShipment->toApiArray())
                ->values(),
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ReturnShipment::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $returnShipments = ReturnShipment::query()
            ->with([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ReturnShipment $returnShipment) => $returnShipment->toApiArray())
            ->values();

        return response()->json([
            'return_shipments' => $returnShipments,
        ]);
    }

    public function show(ReturnShipment $returnShipment): JsonResponse
    {
        Gate::authorize('view', $returnShipment);

        return response()->json([
            'return_shipment' => $returnShipment->load([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function showForRma(Rma $rma): JsonResponse
    {
        $returnShipment = ReturnShipment::query()
            ->where('rma_id', $rma->id)
            ->whereIn('status', ReturnShipment::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();

        if (! $returnShipment) {
            $returnShipment = ReturnShipment::query()
                ->where('rma_id', $rma->id)
                ->orderByDesc('id')
                ->firstOrFail();
        }

        Gate::authorize('view', $returnShipment);

        return response()->json([
            'return_shipment' => $returnShipment->load([
                'items',
                'rma',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreReturnShipmentRequest $request,
        Rma $rma,
        ReturnShipmentService $returnShipments,
    ): JsonResponse {
        Gate::authorize('createForRma', [ReturnShipment::class, $rma]);

        $result = $returnShipments->createForApprovedRma(
            $rma,
            $request->user(),
            $request->validated(),
        );
        $returnShipment = $result['return_shipment'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::RETURN_SHIPMENT_CREATED,
                $returnShipment,
                $returnShipment->buyer_company_id,
                after: $returnShipment->toApiArray(),
            );

            app(DomainNotificationPublisher::class)->returnShipmentCreated($returnShipment);
        }

        return response()->json([
            'return_shipment' => $returnShipment->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function update(
        UpdateReturnShipmentRequest $request,
        ReturnShipment $returnShipment,
        ReturnShipmentService $returnShipments,
    ): JsonResponse {
        Gate::authorize('update', $returnShipment);

        $before = $returnShipment->toApiArray();
        $returnShipment = $returnShipments->updateOperational($returnShipment, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::RETURN_SHIPMENT_UPDATED,
            $returnShipment,
            $returnShipment->buyer_company_id,
            before: $before,
            after: $returnShipment->toApiArray(),
        );

        return response()->json([
            'return_shipment' => $returnShipment->toApiArray(),
        ]);
    }

    public function ship(Request $request, ReturnShipment $returnShipment, ReturnShipmentService $returnShipments): JsonResponse
    {
        Gate::authorize('ship', $returnShipment);

        $before = $returnShipment->toApiArray();
        $returnShipment = $returnShipments->ship($returnShipment);

        app(AuditLogger::class)->record(
            AuditLog::RETURN_SHIPMENT_SHIPPED,
            $returnShipment,
            $returnShipment->supplier_company_id,
            before: $before,
            after: $returnShipment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->returnShipmentShipped($returnShipment);

        return response()->json([
            'return_shipment' => $returnShipment->toApiArray(),
        ]);
    }

    public function deliver(Request $request, ReturnShipment $returnShipment, ReturnShipmentService $returnShipments): JsonResponse
    {
        Gate::authorize('deliver', $returnShipment);

        $before = $returnShipment->toApiArray();
        $returnShipment = $returnShipments->deliver($returnShipment);

        app(AuditLogger::class)->record(
            AuditLog::RETURN_SHIPMENT_DELIVERED,
            $returnShipment,
            $returnShipment->supplier_company_id,
            before: $before,
            after: $returnShipment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->returnShipmentDelivered($returnShipment);

        return response()->json([
            'return_shipment' => $returnShipment->toApiArray(),
        ]);
    }

    public function cancel(Request $request, ReturnShipment $returnShipment, ReturnShipmentService $returnShipments): JsonResponse
    {
        Gate::authorize('cancel', $returnShipment);

        $before = $returnShipment->toApiArray();
        $returnShipment = $returnShipments->cancel($returnShipment);

        app(AuditLogger::class)->record(
            AuditLog::RETURN_SHIPMENT_CANCELLED,
            $returnShipment,
            $returnShipment->buyer_company_id,
            before: $before,
            after: $returnShipment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->returnShipmentCancelled($returnShipment);

        return response()->json([
            'return_shipment' => $returnShipment->toApiArray(),
        ]);
    }
}
