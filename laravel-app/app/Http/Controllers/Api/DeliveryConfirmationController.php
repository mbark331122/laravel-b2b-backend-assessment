<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeliveryConfirmationRequest;
use App\Models\AuditLog;
use App\Models\DeliveryConfirmation;
use App\Models\Shipment;
use App\Services\AuditLogger;
use App\Services\DeliveryConfirmationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeliveryConfirmationController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DeliveryConfirmation::class);

        $user = $request->user();
        $query = DeliveryConfirmation::query()
            ->with([
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'confirmedBy',
            ])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'delivery_confirmations',
            fn (DeliveryConfirmation $confirmation) => $confirmation->toApiArray(),
        );
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DeliveryConfirmation::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $confirmations = DeliveryConfirmation::query()
            ->with([
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'confirmedBy',
            ])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (DeliveryConfirmation $confirmation) => $confirmation->toApiArray())
            ->values();

        return response()->json([
            'delivery_confirmations' => $confirmations,
        ]);
    }

    public function show(DeliveryConfirmation $deliveryConfirmation): JsonResponse
    {
        Gate::authorize('view', $deliveryConfirmation);

        return response()->json([
            'delivery_confirmation' => $deliveryConfirmation->load([
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'confirmedBy',
            ])->toApiArray(),
        ]);
    }

    public function showForShipment(Shipment $shipment): JsonResponse
    {
        $confirmation = DeliveryConfirmation::query()
            ->where('shipment_id', $shipment->id)
            ->firstOrFail();

        Gate::authorize('view', $confirmation);

        return response()->json([
            'delivery_confirmation' => $confirmation->load([
                'shipment',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'confirmedBy',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreDeliveryConfirmationRequest $request,
        Shipment $shipment,
        DeliveryConfirmationService $confirmations,
    ): JsonResponse {
        Gate::authorize('createForShipment', [DeliveryConfirmation::class, $shipment]);

        $result = $confirmations->createForDeliveredShipment(
            $shipment,
            $request->user(),
            $request->validated('notes'),
        );
        $confirmation = $result['confirmation'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::DELIVERY_CONFIRMATION_CREATED,
                $confirmation,
                $confirmation->buyer_company_id,
                after: $confirmation->toApiArray(),
            );
        }

        return response()->json([
            'delivery_confirmation' => $confirmation->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }
}
