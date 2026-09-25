<?php

namespace App\Services;

use App\Models\ApprovalDecision;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflowService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPolicy(Company $company, array $payload): ApprovalPolicy
    {
        return DB::transaction(function () use ($company, $payload) {
            $policy = new ApprovalPolicy;
            $policy->company()->associate($company);
            $policy->name = $payload['name'];
            $policy->approval_type = $payload['approval_type'];
            $policy->is_active = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
            $policy->min_amount = $payload['min_amount'] ?? null;
            $policy->currency = isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null;
            $policy->priority = (int) ($payload['priority'] ?? 0);
            $policy->save();

            $this->syncSteps($policy, $payload['steps'] ?? []);

            $this->auditLogger->record(
                AuditLog::APPROVAL_POLICY_CREATED,
                $policy,
                $company->id,
                after: $policy->fresh('steps')->toApiArray(),
            );

            return $policy->fresh('steps');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePolicy(ApprovalPolicy $policy, array $payload): ApprovalPolicy
    {
        return DB::transaction(function () use ($policy, $payload) {
            /** @var ApprovalPolicy $locked */
            $locked = ApprovalPolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $before = $locked->load('steps')->toApiArray();

            if (array_key_exists('name', $payload)) {
                $locked->name = $payload['name'];
            }
            if (array_key_exists('min_amount', $payload)) {
                $locked->min_amount = $payload['min_amount'];
            }
            if (array_key_exists('currency', $payload)) {
                $locked->currency = $payload['currency'] !== null
                    ? strtoupper((string) $payload['currency'])
                    : null;
            }
            if (array_key_exists('priority', $payload)) {
                $locked->priority = (int) $payload['priority'];
            }
            // approval_type is immutable after create — existing requests snapshot type separately.
            $locked->save();

            if (array_key_exists('steps', $payload)) {
                $locked->steps()->delete();
                $this->syncSteps($locked, $payload['steps']);
            }

            $after = $locked->fresh('steps')->toApiArray();

            $this->auditLogger->record(
                AuditLog::APPROVAL_POLICY_UPDATED,
                $locked,
                $locked->company_id,
                before: $before,
                after: $after,
            );

            return $locked->fresh('steps');
        });
    }

    public function activate(ApprovalPolicy $policy): ApprovalPolicy
    {
        $before = $policy->toApiArray();
        $policy->is_active = true;
        $policy->save();

        $this->auditLogger->record(
            AuditLog::APPROVAL_POLICY_ACTIVATED,
            $policy,
            $policy->company_id,
            before: $before,
            after: $policy->fresh('steps')->toApiArray(),
        );

        return $policy->fresh('steps');
    }

    public function deactivate(ApprovalPolicy $policy): ApprovalPolicy
    {
        $before = $policy->toApiArray();
        $policy->is_active = false;
        $policy->save();

        $this->auditLogger->record(
            AuditLog::APPROVAL_POLICY_DEACTIVATED,
            $policy,
            $policy->company_id,
            before: $before,
            after: $policy->fresh('steps')->toApiArray(),
        );

        return $policy->fresh('steps');
    }

    /**
     * Gate a lifecycle action. Returns null when the action may proceed.
     * Returns an ApprovalRequest when approval is required / pending (caller should not proceed).
     * Uses server-derived context only.
     *
     * @param  array{amount?: string|null, currency?: string|null}  $context
     */
    public function gateOrCreate(User $actor, string $approvalType, Model $target, array $context): ?ApprovalRequest
    {
        $company = $actor->company;
        if ($company === null) {
            return null;
        }

        $targetCompanyId = $this->resolveTargetCompanyId($target);
        if ($targetCompanyId === null || $targetCompanyId !== $company->id) {
            throw ValidationException::withMessages([
                'target' => 'Approval target does not belong to your company.',
            ]);
        }

        return DB::transaction(function () use ($actor, $company, $approvalType, $target, $context) {
            $approved = ApprovalRequest::query()
                ->where('company_id', $company->id)
                ->where('approval_type', $approvalType)
                ->where('target_type', $target::class)
                ->where('target_id', $target->getKey())
                ->where('status', ApprovalRequest::STATUS_APPROVED)
                ->lockForUpdate()
                ->exists();

            if ($approved) {
                return null;
            }

            $pending = ApprovalRequest::query()
                ->where('pending_lock', ApprovalRequest::pendingLockKey($approvalType, $target::class, (int) $target->getKey()))
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                return $pending->load('decisions');
            }

            $policy = $this->matchPolicy($company, $approvalType, $context);
            if ($policy === null) {
                return null;
            }

            return $this->createRequest($actor, $company, $policy, $target, $context);
        });
    }

    public function approve(ApprovalRequest $request, User $actor, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);
            $this->assertNotSelfApproval($locked, $actor);

            $decision = ApprovalDecision::query()
                ->where('approval_request_id', $locked->id)
                ->where('step_order', $locked->current_step_order)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $decision->isPending()) {
                throw ValidationException::withMessages([
                    'approval' => 'This approval step has already been decided.',
                ]);
            }

            $this->assertActorCanDecideStep($actor, $decision);

            $decision->status = ApprovalDecision::STATUS_APPROVED;
            $decision->decision = ApprovalDecision::DECISION_APPROVE;
            $decision->reason = $reason;
            $decision->decided_by_user_id = $actor->id;
            $decision->decided_at = now();
            $decision->save();

            $this->auditLogger->record(
                AuditLog::APPROVAL_STEP_DECIDED,
                $decision,
                $locked->company_id,
                after: $decision->toApiArray(),
            );

            $maxStep = (int) collect($locked->policy_steps_snapshot)->max('step_order');

            if ((int) $locked->current_step_order >= $maxStep) {
                $locked->status = ApprovalRequest::STATUS_APPROVED;
                $locked->pending_lock = null;
                $locked->completed_at = now();
                $locked->save();

                $this->auditLogger->record(
                    AuditLog::APPROVAL_REQUEST_APPROVED,
                    $locked,
                    $locked->company_id,
                    after: $locked->fresh('decisions')->toApiArray(),
                );

                app(DomainNotificationPublisher::class)->approvalRequestApproved($locked);
            } else {
                $locked->current_step_order = (int) $locked->current_step_order + 1;
                $locked->save();

                app(DomainNotificationPublisher::class)->approvalStepAdvanced($locked);
            }

            return $locked->fresh('decisions');
        });
    }

    public function reject(ApprovalRequest $request, User $actor, string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);
            $this->assertNotSelfApproval($locked, $actor);

            $decision = ApprovalDecision::query()
                ->where('approval_request_id', $locked->id)
                ->where('step_order', $locked->current_step_order)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $decision->isPending()) {
                throw ValidationException::withMessages([
                    'approval' => 'This approval step has already been decided.',
                ]);
            }

            $this->assertActorCanDecideStep($actor, $decision);

            $decision->status = ApprovalDecision::STATUS_REJECTED;
            $decision->decision = ApprovalDecision::DECISION_REJECT;
            $decision->reason = $reason;
            $decision->decided_by_user_id = $actor->id;
            $decision->decided_at = now();
            $decision->save();

            $this->auditLogger->record(
                AuditLog::APPROVAL_STEP_DECIDED,
                $decision,
                $locked->company_id,
                after: $decision->toApiArray(),
            );

            $locked->status = ApprovalRequest::STATUS_REJECTED;
            $locked->rejection_reason = $reason;
            $locked->pending_lock = null;
            $locked->completed_at = now();
            $locked->save();

            $this->auditLogger->record(
                AuditLog::APPROVAL_REQUEST_REJECTED,
                $locked,
                $locked->company_id,
                after: $locked->fresh('decisions')->toApiArray(),
                reason: $reason,
            );

            app(DomainNotificationPublisher::class)->approvalRequestRejected($locked);

            return $locked->fresh('decisions');
        });
    }

    public function cancel(ApprovalRequest $request, User $actor): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);

            if (! $actor->isAdmin() && $actor->id !== $locked->requester_id) {
                throw ValidationException::withMessages([
                    'approval' => 'Only the requester can cancel this approval request.',
                ]);
            }

            $before = $locked->toApiArray();
            $locked->status = ApprovalRequest::STATUS_CANCELLED;
            $locked->pending_lock = null;
            $locked->completed_at = now();
            $locked->save();

            $this->auditLogger->record(
                AuditLog::APPROVAL_REQUEST_CANCELLED,
                $locked,
                $locked->company_id,
                before: $before,
                after: $locked->fresh('decisions')->toApiArray(),
            );

            app(DomainNotificationPublisher::class)->approvalRequestCancelled($locked);

            return $locked->fresh('decisions');
        });
    }

    /**
     * @param  array{amount?: string|null, currency?: string|null}  $context
     */
    public function contextForRfq(Rfq $rfq): array
    {
        return [
            'amount' => null,
            'currency' => $rfq->currency ? strtoupper((string) $rfq->currency) : null,
            'quantity' => $rfq->quantity,
            'rfq_status' => $rfq->status,
        ];
    }

    /**
     * @param  array{amount?: string|null, currency?: string|null}  $context
     */
    public function contextForPurchaseOrder(PurchaseOrder $po): array
    {
        return [
            'amount' => (string) $po->total,
            'currency' => $po->currency ? strtoupper((string) $po->currency) : null,
            'po_status' => $po->status,
        ];
    }

    /**
     * @param  list<array{step_order?: int, approver_role: string}>  $steps
     */
    private function syncSteps(ApprovalPolicy $policy, array $steps): void
    {
        if ($steps === []) {
            throw ValidationException::withMessages([
                'steps' => 'At least one approval step is required.',
            ]);
        }

        $orders = [];
        foreach ($steps as $index => $stepPayload) {
            $order = (int) ($stepPayload['step_order'] ?? ($index + 1));
            if (isset($orders[$order])) {
                throw ValidationException::withMessages([
                    'steps' => 'Duplicate step_order values are not allowed.',
                ]);
            }
            $orders[$order] = true;

            $role = (string) $stepPayload['approver_role'];
            $this->assertInvitableApproverRole($role);

            $step = new ApprovalPolicyStep;
            $step->approval_policy_id = $policy->id;
            $step->step_order = $order;
            $step->approver_role = $role;
            $step->save();
        }
    }

    /**
     * @param  array{amount?: string|null, currency?: string|null}  $context
     */
    private function matchPolicy(Company $company, string $approvalType, array $context): ?ApprovalPolicy
    {
        $candidates = ApprovalPolicy::query()
            ->where('company_id', $company->id)
            ->where('approval_type', $approvalType)
            ->where('is_active', true)
            ->with('steps')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $policy) {
            if ($policy->steps->isEmpty()) {
                continue;
            }

            if ($policy->min_amount !== null) {
                $amount = $context['amount'] ?? null;
                if ($amount === null || (float) $amount < (float) $policy->min_amount) {
                    continue;
                }
            }

            if ($policy->currency !== null) {
                $currency = isset($context['currency']) ? strtoupper((string) $context['currency']) : null;
                if ($currency !== $policy->currency) {
                    continue;
                }
            }

            return $policy;
        }

        return null;
    }

    /**
     * @param  array{amount?: string|null, currency?: string|null}  $context
     */
    private function createRequest(
        User $actor,
        Company $company,
        ApprovalPolicy $policy,
        Model $target,
        array $context,
    ): ApprovalRequest {
        $stepsSnapshot = $policy->steps
            ->sortBy('step_order')
            ->values()
            ->map(fn (ApprovalPolicyStep $step) => [
                'step_order' => (int) $step->step_order,
                'approver_role' => $step->approver_role,
            ])
            ->all();

        $request = new ApprovalRequest;
        $request->company()->associate($company);
        $request->policy()->associate($policy);
        $request->approval_type = $policy->approval_type;
        $request->target_type = $target::class;
        $request->target_id = (int) $target->getKey();
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requester()->associate($actor);
        $request->current_step_order = (int) $stepsSnapshot[0]['step_order'];
        $request->policy_name_snapshot = $policy->name;
        $request->policy_steps_snapshot = $stepsSnapshot;
        $request->target_context_snapshot = [
            'amount' => $context['amount'] ?? null,
            'currency' => $context['currency'] ?? null,
        ];
        $request->pending_lock = ApprovalRequest::pendingLockKey(
            $policy->approval_type,
            $target::class,
            (int) $target->getKey(),
        );
        $request->save();

        foreach ($stepsSnapshot as $step) {
            $decision = new ApprovalDecision;
            $decision->approval_request_id = $request->id;
            $decision->step_order = (int) $step['step_order'];
            $decision->approver_role = $step['approver_role'];
            $decision->status = ApprovalDecision::STATUS_PENDING;
            $decision->save();
        }

        $this->auditLogger->record(
            AuditLog::APPROVAL_REQUEST_CREATED,
            $request,
            $company->id,
            after: $request->fresh('decisions')->toApiArray(),
        );

        app(DomainNotificationPublisher::class)->approvalRequestCreated($request);

        return $request->fresh('decisions');
    }

    private function resolveTargetCompanyId(Model $target): ?int
    {
        if ($target instanceof Rfq) {
            return $target->company_id;
        }

        if ($target instanceof PurchaseOrder) {
            return $target->buyer_company_id;
        }

        return null;
    }

    private function assertPending(ApprovalRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'approval' => 'This approval request is no longer pending.',
            ]);
        }
    }

    private function assertNotSelfApproval(ApprovalRequest $request, User $actor): void
    {
        if ($actor->id === $request->requester_id) {
            throw ValidationException::withMessages([
                'approval' => 'You cannot approve or reject your own approval request.',
            ]);
        }
    }

    private function assertActorCanDecideStep(User $actor, ApprovalDecision $decision): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->role?->name !== $decision->approver_role) {
            throw ValidationException::withMessages([
                'approval' => 'You are not an authorized approver for this step.',
            ]);
        }
    }

    private function assertInvitableApproverRole(string $role): void
    {
        $allowed = [Role::COMPANY_USER, Role::SUPPLIER_USER, Role::INTERMEDIARY_USER];

        if (! in_array($role, $allowed, true)) {
            throw ValidationException::withMessages([
                'steps' => 'Invalid approver role.',
            ]);
        }
    }
}
