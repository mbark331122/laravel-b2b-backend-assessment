<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRfqRequest;
use App\Http\Requests\UpdateRfqRequest;
use App\Models\AuditLog;
use App\Models\Rfq;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RfqController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Rfq::class);

        $rfqs = Rfq::query()
            ->visibleTo($request->user())
            ->latest('id')
            ->get()
            ->map(fn (Rfq $rfq) => $rfq->toApiArray())
            ->values();

        return response()->json([
            'rfqs' => $rfqs,
        ]);
    }

    public function store(StoreRfqRequest $request): JsonResponse
    {
        $this->authorize('create', Rfq::class);

        $rfq = $request->user()->company->rfqs()->create([
            ...$request->validated(),
            'status' => Rfq::STATUS_DRAFT,
        ]);

        app(AuditLogger::class)->record(
            AuditLog::RFQ_CREATED,
            $rfq,
            $rfq->company_id,
            after: $rfq->toApiArray(),
        );

        return response()->json([
            'rfq' => $rfq->toApiArray(),
        ], 201);
    }

    public function show(Rfq $rfq): JsonResponse
    {
        $this->authorize('view', $rfq);

        return response()->json([
            'rfq' => $rfq->toApiArray(),
        ]);
    }

    public function update(UpdateRfqRequest $request, Rfq $rfq): JsonResponse
    {
        $this->authorize('update', $rfq);

        $before = $rfq->toApiArray();
        $rfq->update($request->validated());
        $after = $rfq->fresh()->toApiArray();

        app(AuditLogger::class)->record(
            AuditLog::RFQ_UPDATED,
            $rfq,
            $rfq->company_id,
            before: $before,
            after: $after,
        );

        return response()->json([
            'rfq' => $after,
        ]);
    }
}
