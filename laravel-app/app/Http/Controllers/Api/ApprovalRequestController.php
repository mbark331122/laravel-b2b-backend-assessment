<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideApprovalRequest;
use App\Http\Requests\RejectApprovalRequest;
use App\Models\ApprovalRequest;
use App\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApprovalRequestController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        $query = ApprovalRequest::query()->with('decisions')->orderByDesc('id');

        if (! $request->user()->isAdmin()) {
            $query->where('company_id', $request->user()->company_id);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'approval_requests',
            fn (ApprovalRequest $approvalRequest) => $approvalRequest->toApiArray(),
        );
    }

    public function show(ApprovalRequest $approvalRequest): JsonResponse
    {
        $this->authorize('view', $approvalRequest);

        return response()->json([
            'approval_request' => $approvalRequest->load('decisions')->toApiArray(),
        ]);
    }

    public function approve(
        DecideApprovalRequest $request,
        ApprovalRequest $approvalRequest,
        ApprovalWorkflowService $service,
    ): JsonResponse {
        $this->authorize('decide', $approvalRequest);

        try {
            $result = $service->approve(
                $approvalRequest,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json([
            'approval_request' => $result->toApiArray(),
        ]);
    }

    public function reject(
        RejectApprovalRequest $request,
        ApprovalRequest $approvalRequest,
        ApprovalWorkflowService $service,
    ): JsonResponse {
        $this->authorize('decide', $approvalRequest);

        $result = $service->reject(
            $approvalRequest,
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json([
            'approval_request' => $result->toApiArray(),
        ]);
    }

    public function cancel(
        Request $request,
        ApprovalRequest $approvalRequest,
        ApprovalWorkflowService $service,
    ): JsonResponse {
        $this->authorize('cancel', $approvalRequest);

        $result = $service->cancel($approvalRequest, $request->user());

        return response()->json([
            'approval_request' => $result->toApiArray(),
        ]);
    }
}
