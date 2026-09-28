<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApprovalPolicyRequest;
use App\Http\Requests\UpdateApprovalPolicyRequest;
use App\Models\ApprovalPolicy;
use App\Services\ApprovalWorkflowService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalPolicyController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalPolicy::class);

        $query = ApprovalPolicy::query()->with('steps')->orderByDesc('priority')->orderBy('id');

        if (! $request->user()->isAdmin()) {
            $query->where('company_id', $request->user()->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'approval_policies',
            fn (ApprovalPolicy $policy) => $policy->toApiArray(),
        );
    }

    public function store(StoreApprovalPolicyRequest $request, ApprovalWorkflowService $service): JsonResponse
    {
        $this->authorize('create', ApprovalPolicy::class);

        $policy = $service->createPolicy($request->user()->company, $request->validated());

        return response()->json([
            'approval_policy' => $policy->toApiArray(),
        ], 201);
    }

    public function show(ApprovalPolicy $approvalPolicy): JsonResponse
    {
        $this->authorize('view', $approvalPolicy);

        return response()->json([
            'approval_policy' => $approvalPolicy->load('steps')->toApiArray(),
        ]);
    }

    public function update(
        UpdateApprovalPolicyRequest $request,
        ApprovalPolicy $approvalPolicy,
        ApprovalWorkflowService $service,
    ): JsonResponse {
        $this->authorize('update', $approvalPolicy);

        $policy = $service->updatePolicy($approvalPolicy, $request->validated());

        return response()->json([
            'approval_policy' => $policy->toApiArray(),
        ]);
    }

    public function activate(ApprovalPolicy $approvalPolicy, ApprovalWorkflowService $service): JsonResponse
    {
        $this->authorize('manage', $approvalPolicy);

        return response()->json([
            'approval_policy' => $service->activate($approvalPolicy)->toApiArray(),
        ]);
    }

    public function deactivate(ApprovalPolicy $approvalPolicy, ApprovalWorkflowService $service): JsonResponse
    {
        $this->authorize('manage', $approvalPolicy);

        return response()->json([
            'approval_policy' => $service->deactivate($approvalPolicy)->toApiArray(),
        ]);
    }
}
