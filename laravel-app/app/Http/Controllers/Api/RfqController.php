<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRfqRequest;
use App\Http\Requests\UpdateRfqRequest;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Rfq;
use App\Services\ApprovalWorkflowService;
use App\Services\AuditLogger;
use App\Services\DomainNotificationPublisher;
use App\Services\RfqService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RfqController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Rfq::class);

        $filters = $request->only([
            'status',
            'created_from',
            'created_to',
            'updated_from',
            'updated_to',
        ]);

        $query = Rfq::query()
            ->with('items')
            ->visibleTo($request->user())
            ->applyFilters($filters)
            ->latest('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'rfqs',
            fn (Rfq $rfq) => $rfq->toApiArray(),
        );
    }

    public function store(StoreRfqRequest $request, RfqService $rfqService): JsonResponse
    {
        $this->authorize('create', Rfq::class);

        $data = $request->safe()->except(['items']);
        $data['title'] = $data['title'] ?? $data['commodity'];

        $rfq = $request->user()->company->rfqs()->make($data);
        $rfq->status = Rfq::STATUS_DRAFT;
        $rfq->save();

        $items = $request->validated('items', []);
        if ($items !== []) {
            foreach ($items as $itemPayload) {
                $rfqService->createItem($rfq, $itemPayload);
            }
        } else {
            $rfqService->ensureLegacyItem($rfq);
        }

        app(AuditLogger::class)->record(
            AuditLog::RFQ_CREATED,
            $rfq,
            $rfq->company_id,
            after: $rfq->fresh('items')->toApiArray(),
        );

        return response()->json([
            'rfq' => $rfq->fresh('items')->toApiArray(),
        ], 201);
    }

    public function show(Rfq $rfq): JsonResponse
    {
        $this->authorize('view', $rfq);

        return response()->json([
            'rfq' => $rfq->load('items')->toApiArray(),
        ]);
    }

    public function update(UpdateRfqRequest $request, Rfq $rfq): JsonResponse
    {
        $this->authorize('update', $rfq);

        $before = $rfq->load('items')->toApiArray();
        $rfq->fill($request->validated());
        if ($rfq->isDirty('commodity') && ! $request->filled('title') && $rfq->title === $before['title']) {
            // keep title unless explicitly changed
        }
        $rfq->save();
        $after = $rfq->fresh('items')->toApiArray();

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

    public function destroy(Rfq $rfq): JsonResponse
    {
        $this->authorize('delete', $rfq);

        $before = $rfq->load('items')->toApiArray();
        $companyId = $rfq->company_id;
        $rfq->delete();

        app(AuditLogger::class)->record(
            AuditLog::RFQ_DELETED,
            $rfq,
            $companyId,
            before: $before,
            after: ['id' => $before['id'], 'deleted' => true],
        );

        return response()->json([
            'message' => 'RFQ deleted.',
        ]);
    }

    public function submit(Request $request, Rfq $rfq, ApprovalWorkflowService $approvals): JsonResponse
    {
        $this->authorize('submit', $rfq);

        $blocked = $approvals->gateOrCreate(
            $request->user(),
            ApprovalPolicy::TYPE_RFQ_SUBMIT,
            $rfq,
            $approvals->contextForRfq($rfq),
        );

        if ($blocked !== null) {
            return response()->json([
                'message' => 'Approval is required before this RFQ can be submitted.',
                'approval_request' => $blocked->toApiArray(),
            ], 422);
        }

        return $this->transition($request, $rfq, 'submit', Rfq::STATUS_SUBMITTED, AuditLog::RFQ_SUBMITTED);
    }

    public function cancel(Request $request, Rfq $rfq): JsonResponse
    {
        return $this->transition($request, $rfq, 'cancel', Rfq::STATUS_CANCELLED, AuditLog::RFQ_CANCELLED);
    }

    public function close(Request $request, Rfq $rfq): JsonResponse
    {
        return $this->transition($request, $rfq, 'close', Rfq::STATUS_CLOSED, AuditLog::RFQ_CLOSED);
    }

    private function transition(Request $request, Rfq $rfq, string $ability, string $status, string $auditAction): JsonResponse
    {
        $this->authorize($ability, $rfq);

        $before = $rfq->load('items')->toApiArray();

        try {
            $rfq->transitionTo($status);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $after = $rfq->fresh('items')->toApiArray();

        app(AuditLogger::class)->record(
            $auditAction,
            $rfq,
            $rfq->company_id,
            before: $before,
            after: $after,
            reason: $request->input('reason'),
        );

        if ($auditAction === AuditLog::RFQ_SUBMITTED) {
            app(DomainNotificationPublisher::class)->rfqSubmitted($rfq->fresh());
        }

        return response()->json([
            'rfq' => $after,
        ]);
    }
}
