<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNegotiationOfferRequest;
use App\Http\Requests\StoreNegotiationRequest;
use App\Models\AuditLog;
use App\Models\Negotiation;
use App\Models\NegotiationOffer;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Services\AuditLogger;
use App\Services\NegotiationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NegotiationController extends Controller
{
    use AuthorizesRequests;

    public function storeForQuotation(
        StoreNegotiationRequest $request,
        Quotation $quotation,
        NegotiationService $negotiations,
    ): JsonResponse {
        Gate::authorize('createForQuotation', [Negotiation::class, $quotation]);

        $negotiation = $negotiations->open(
            $quotation,
            $request->user(),
            $request->safe()->only(['valid_until'])
        );

        app(AuditLogger::class)->record(
            AuditLog::NEGOTIATION_CREATED,
            $negotiation,
            $request->user()->company_id,
            after: $negotiation->toApiArray(includeOffers: true),
        );

        $initial = $negotiation->offers->first();
        if ($initial) {
            app(AuditLogger::class)->record(
                AuditLog::NEGOTIATION_OFFER_CREATED,
                $initial,
                $request->user()->company_id,
                after: $initial->toApiArray(),
            );
        }

        return response()->json([
            'negotiation' => $negotiation->toApiArray(includeOffers: true),
        ], 201);
    }

    public function indexForRfq(Rfq $rfq): JsonResponse
    {
        Gate::authorize('viewAnyForRfq', [Negotiation::class, $rfq]);

        $negotiations = Negotiation::query()
            ->with(['buyerCompany', 'supplierCompany.supplierProfile', 'acceptedOffer'])
            ->where('rfq_id', $rfq->id)
            ->where('buyer_company_id', $rfq->company_id)
            ->orderBy('id')
            ->get()
            ->each(fn (Negotiation $n) => $n->refreshExpiration())
            ->map(fn (Negotiation $n) => $n->fresh([
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'acceptedOffer',
                'acceptedByCompany',
            ])->toApiArray())
            ->values();

        return response()->json([
            'rfq_id' => $rfq->id,
            'negotiations' => $negotiations,
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyForSupplier', Negotiation::class);

        $companyId = (int) $request->user()->company_id;

        $negotiations = Negotiation::query()
            ->with(['buyerCompany', 'supplierCompany.supplierProfile', 'acceptedOffer'])
            ->where('supplier_company_id', $companyId)
            ->orderByDesc('id')
            ->get()
            ->each(fn (Negotiation $n) => $n->refreshExpiration())
            ->map(fn (Negotiation $n) => $n->fresh([
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'acceptedOffer',
                'acceptedByCompany',
            ])->toApiArray())
            ->values();

        return response()->json([
            'negotiations' => $negotiations,
        ]);
    }

    public function show(Negotiation $negotiation): JsonResponse
    {
        Gate::authorize('view', $negotiation);
        $negotiation->refreshExpiration();

        return response()->json([
            'negotiation' => $negotiation->fresh([
                'offers.items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
                'acceptedOffer.items',
                'acceptedByCompany',
            ])->toApiArray(includeOffers: true),
        ]);
    }

    public function offers(Negotiation $negotiation): JsonResponse
    {
        Gate::authorize('view', $negotiation);
        $negotiation->refreshExpiration();

        $offers = $negotiation->offers()
            ->with(['items', 'createdByCompany', 'createdByUser'])
            ->orderBy('sequence')
            ->get()
            ->map(fn (NegotiationOffer $offer) => $offer->toApiArray())
            ->values();

        return response()->json([
            'negotiation_id' => $negotiation->id,
            'offers' => $offers,
        ]);
    }

    public function storeOffer(
        StoreNegotiationOfferRequest $request,
        Negotiation $negotiation,
        NegotiationService $negotiations,
    ): JsonResponse {
        Gate::authorize('createOffer', $negotiation);

        $offer = $negotiations->createCounterOffer(
            $negotiation,
            $request->user(),
            $request->safe()->except([
                'side',
                'party',
                'company_id',
                'supplier_id',
                'buyer_id',
                'buyer_company_id',
                'supplier_company_id',
                'tenant_id',
                'user_id',
                'subtotal',
                'total',
                'line_total',
                'sequence',
                'status',
            ])
        );

        app(AuditLogger::class)->record(
            AuditLog::NEGOTIATION_OFFER_CREATED,
            $offer,
            $request->user()->company_id,
            after: $offer->toApiArray(),
        );

        return response()->json([
            'offer' => $offer->toApiArray(),
            'negotiation' => $negotiation->fresh([
                'offers.items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(includeOffers: true),
        ], 201);
    }

    public function acceptOffer(
        Request $request,
        Negotiation $negotiation,
        NegotiationOffer $offer,
        NegotiationService $negotiations,
    ): JsonResponse {
        Gate::authorize('acceptOffer', $negotiation);

        if ((int) $offer->negotiation_id !== (int) $negotiation->id) {
            abort(404);
        }

        $before = $negotiation->load(['offers', 'acceptedOffer'])->toApiArray(includeOffers: true);
        $negotiation = $negotiations->acceptOffer($negotiation, $offer, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::NEGOTIATION_OFFER_ACCEPTED,
            $negotiation,
            $request->user()->company_id,
            before: $before,
            after: $negotiation->toApiArray(includeOffers: true),
            reason: $request->input('reason'),
        );

        return response()->json([
            'negotiation' => $negotiation->toApiArray(includeOffers: true),
        ]);
    }

    public function reject(Request $request, Negotiation $negotiation, NegotiationService $negotiations): JsonResponse
    {
        Gate::authorize('reject', $negotiation);

        $before = $negotiation->toApiArray(includeOffers: true);
        $negotiation = $negotiations->reject($negotiation, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::NEGOTIATION_REJECTED,
            $negotiation,
            $request->user()->company_id,
            before: $before,
            after: $negotiation->toApiArray(includeOffers: true),
            reason: $request->input('reason'),
        );

        return response()->json([
            'negotiation' => $negotiation->toApiArray(includeOffers: true),
        ]);
    }

    public function withdraw(Request $request, Negotiation $negotiation, NegotiationService $negotiations): JsonResponse
    {
        Gate::authorize('withdraw', $negotiation);

        $before = $negotiation->toApiArray(includeOffers: true);
        $negotiation = $negotiations->withdraw($negotiation, $request->user());

        app(AuditLogger::class)->record(
            AuditLog::NEGOTIATION_WITHDRAWN,
            $negotiation,
            $request->user()->company_id,
            before: $before,
            after: $negotiation->toApiArray(includeOffers: true),
            reason: $request->input('reason'),
        );

        return response()->json([
            'negotiation' => $negotiation->toApiArray(includeOffers: true),
        ]);
    }
}
