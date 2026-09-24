<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectRmaRequest;
use App\Http\Requests\StoreRmaRequest;
use App\Models\AuditLog;
use App\Models\Rma;
use App\Models\Shipment;
use App\Services\AuditLogger;
use App\Services\RmaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RmaController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Rma::class);

        $user = $request->user();
        $query = Rma::query()
            ->with([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'rmas',
            fn (Rma $rma) => $rma->toApiArray(),
        );
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Rma::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $rmas = Rma::query()
            ->with([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Rma $rma) => $rma->toApiArray())
            ->values();

        return response()->json([
            'rmas' => $rmas,
        ]);
    }

    public function show(Rma $rma): JsonResponse
    {
        Gate::authorize('view', $rma);

        return response()->json([
            'rma' => $rma->load([
                'items',
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'requestedBy',
                'reviewedBy',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreRmaRequest $request,
        Shipment $shipment,
        RmaService $rmas,
    ): JsonResponse {
        Gate::authorize('createForShipment', [Rma::class, $shipment]);

        $rma = $rmas->createForDeliveredShipment(
            $shipment,
            $request->user(),
            $request->validated(),
        );

        app(AuditLogger::class)->record(
            AuditLog::RMA_CREATED,
            $rma,
            $rma->buyer_company_id,
            after: $rma->toApiArray(),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ], 201);
    }

    public function cancel(Request $request, Rma $rma, RmaService $rmas): JsonResponse
    {
        Gate::authorize('cancel', $rma);

        $before = $rma->toApiArray();
        $rma = $rmas->cancel($rma, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::RMA_CANCELLED,
            $rma,
            $rma->buyer_company_id,
            before: $before,
            after: $rma->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ]);
    }

    public function approve(Request $request, Rma $rma, RmaService $rmas): JsonResponse
    {
        Gate::authorize('approve', $rma);

        $before = $rma->toApiArray();
        $rma = $rmas->approve($rma, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::RMA_APPROVED,
            $rma,
            $rma->supplier_company_id,
            before: $before,
            after: $rma->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ]);
    }

    public function reject(RejectRmaRequest $request, Rma $rma, RmaService $rmas): JsonResponse
    {
        Gate::authorize('reject', $rma);

        $before = $rma->toApiArray();
        $rma = $rmas->reject($rma, $request->user(), (string) $request->validated('rejection_reason'));

        app(AuditLogger::class)->record(
            AuditLog::RMA_REJECTED,
            $rma,
            $rma->supplier_company_id,
            before: $before,
            after: $rma->toApiArray(),
            reason: $request->validated('rejection_reason'),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ]);
    }

    public function received(Request $request, Rma $rma, RmaService $rmas): JsonResponse
    {
        Gate::authorize('receive', $rma);

        $before = $rma->toApiArray();
        $rma = $rmas->markReceived($rma, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::RMA_RECEIVED,
            $rma,
            $rma->supplier_company_id,
            before: $before,
            after: $rma->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ]);
    }

    public function close(Request $request, Rma $rma, RmaService $rmas): JsonResponse
    {
        Gate::authorize('close', $rma);

        $before = $rma->toApiArray();
        $rma = $rmas->close($rma, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::RMA_CLOSED,
            $rma,
            $rma->supplier_company_id,
            before: $before,
            after: $rma->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'rma' => $rma->toApiArray(),
        ]);
    }
}
