<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierRfqController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyDistributed', RfqDistribution::class);

        $companyId = (int) $request->user()->company_id;

        $rfqs = Rfq::query()
            ->with(['items', 'distributions' => function ($query) use ($companyId): void {
                $query->active()->where('supplier_company_id', $companyId);
            }])
            ->distributedToSupplierCompany($companyId)
            ->latest('id')
            ->get()
            ->map(function (Rfq $rfq) {
                $distribution = $rfq->distributions->first();

                return $rfq->toSupplierApiArray($distribution);
            })
            ->values();

        return response()->json([
            'rfqs' => $rfqs,
        ]);
    }

    public function show(Request $request, Rfq $rfq): JsonResponse
    {
        Gate::authorize('viewDistributed', [RfqDistribution::class, $rfq]);

        $companyId = (int) $request->user()->company_id;
        $distribution = $rfq->distributions()
            ->active()
            ->where('supplier_company_id', $companyId)
            ->firstOrFail();

        return response()->json([
            'rfq' => $rfq->load('items')->toSupplierApiArray($distribution),
        ]);
    }
}
