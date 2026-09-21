<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Rfq;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BuyerQuotationController extends Controller
{
    use AuthorizesRequests;

    public function index(Rfq $rfq): JsonResponse
    {
        Gate::authorize('viewAnyForRfq', [Quotation::class, $rfq]);

        $quotations = Quotation::query()
            ->with(['items', 'supplierCompany.supplierProfile'])
            ->where('rfq_id', $rfq->id)
            ->buyerVisible()
            ->orderBy('id')
            ->get()
            ->each(fn (Quotation $quotation) => $quotation->refreshExpiration())
            ->map(fn (Quotation $quotation) => $quotation->fresh(['items', 'supplierCompany.supplierProfile'])->toApiArray())
            ->values();

        return response()->json([
            'rfq_id' => $rfq->id,
            'quotations' => $quotations,
        ]);
    }

    public function compare(Rfq $rfq): JsonResponse
    {
        Gate::authorize('compare', [Quotation::class, $rfq]);

        $quotations = Quotation::query()
            ->with(['items', 'supplierCompany.supplierProfile'])
            ->where('rfq_id', $rfq->id)
            ->buyerVisible()
            ->orderBy('id')
            ->get()
            ->each(fn (Quotation $quotation) => $quotation->refreshExpiration())
            ->sortBy(function (Quotation $quotation) {
                $name = $quotation->supplierCompany?->supplierProfile?->display_name
                    ?? $quotation->supplierCompany?->name
                    ?? '';

                return sprintf('%s#%010d', mb_strtolower($name), $quotation->id);
            })
            ->values()
            ->map(fn (Quotation $quotation) => $quotation->fresh(['items', 'supplierCompany.supplierProfile'])->toCompareArray())
            ->values();

        return response()->json([
            'rfq_id' => $rfq->id,
            'comparison' => $quotations,
        ]);
    }

    public function show(Rfq $rfq, Quotation $quotation): JsonResponse
    {
        if ((int) $quotation->rfq_id !== (int) $rfq->id) {
            abort(404);
        }

        Gate::authorize('viewForBuyer', $quotation);
        $quotation->refreshExpiration();

        return response()->json([
            'quotation' => $quotation->fresh(['items', 'supplierCompany.supplierProfile'])->toApiArray(),
        ]);
    }
}
