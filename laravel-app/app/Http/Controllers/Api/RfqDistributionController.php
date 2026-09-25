<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRfqDistributionRequest;
use App\Models\AuditLog;
use App\Models\Rfq;
use App\Models\RfqDistribution;
use App\Models\SupplierProfile;
use App\Services\AuditLogger;
use App\Services\DomainNotificationPublisher;
use App\Services\SupplierMatchingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RfqDistributionController extends Controller
{
    use AuthorizesRequests;

    public function match(Request $request, Rfq $rfq, SupplierMatchingService $matching): JsonResponse
    {
        Gate::authorize('match', [RfqDistribution::class, $rfq]);

        $profiles = $matching->matchForRfq($rfq)
            ->map(fn (SupplierProfile $profile) => $profile->toApiArray())
            ->values();

        return response()->json([
            'rfq_id' => $rfq->id,
            'suppliers' => $profiles,
        ]);
    }

    public function index(Request $request, Rfq $rfq): JsonResponse
    {
        Gate::authorize('viewAny', [RfqDistribution::class, $rfq]);

        $distributions = $rfq->distributions()
            ->with(['supplierProfile', 'supplierCompany'])
            ->orderBy('id')
            ->get()
            ->map(fn (RfqDistribution $distribution) => $distribution->toApiArray())
            ->values();

        return response()->json([
            'rfq_id' => $rfq->id,
            'distributions' => $distributions,
        ]);
    }

    public function store(StoreRfqDistributionRequest $request, Rfq $rfq, SupplierMatchingService $matching): JsonResponse
    {
        Gate::authorize('create', [RfqDistribution::class, $rfq]);

        $distributions = $matching->distribute(
            $rfq,
            $request->validated('supplier_profile_ids')
        );

        foreach ($distributions as $distribution) {
            app(AuditLogger::class)->record(
                AuditLog::RFQ_DISTRIBUTED,
                $distribution,
                $rfq->company_id,
                after: $distribution->toApiArray(),
            );

            app(DomainNotificationPublisher::class)->rfqDistributed($rfq, $distribution);
        }

        return response()->json([
            'rfq_id' => $rfq->id,
            'distributions' => $distributions->map(fn (RfqDistribution $d) => $d->toApiArray())->values(),
        ], 201);
    }

    public function withdraw(Request $request, Rfq $rfq, RfqDistribution $distribution, SupplierMatchingService $matching): JsonResponse
    {
        $this->assertDistributionBelongsToRfq($rfq, $distribution);
        Gate::authorize('withdraw', $distribution);

        $before = $distribution->toApiArray();
        $distribution = $matching->withdraw($distribution);

        app(AuditLogger::class)->record(
            AuditLog::RFQ_DISTRIBUTION_WITHDRAWN,
            $distribution,
            $rfq->company_id,
            before: $before,
            after: $distribution->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'distribution' => $distribution->toApiArray(),
        ]);
    }

    private function assertDistributionBelongsToRfq(Rfq $rfq, RfqDistribution $distribution): void
    {
        if ((int) $distribution->rfq_id !== (int) $rfq->id) {
            abort(404);
        }
    }
}
