<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankChangeRequest;
use App\Models\AuditLog;
use App\Models\BankChangeRequest;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BankChangeRequestController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreBankChangeRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('requestBankChange', $supplier);

        $account = $supplier->bankAccount()->firstOrFail();

        $changeRequest = new BankChangeRequest;
        $changeRequest->bankAccount()->associate($account);
        $changeRequest->company()->associate($supplier->company);
        $changeRequest->requestedBy()->associate($request->user());
        $changeRequest->current_iban = $account->iban;
        $changeRequest->proposed_iban = $request->validated('proposed_iban');
        $changeRequest->status = BankChangeRequest::STATUS_PENDING;
        $changeRequest->save();

        app(AuditLogger::class)->record(
            AuditLog::BANK_CHANGE_REQUESTED,
            $changeRequest,
            $supplier->company_id,
            before: ['iban' => $account->iban],
            after: ['iban' => $changeRequest->proposed_iban],
        );

        return response()->json([
            'change_request' => $changeRequest->toApiArray(),
            'bank_account' => $account->fresh()->toApiArray(),
        ], 201);
    }

    public function show(BankChangeRequest $bankChangeRequest): JsonResponse
    {
        $this->authorize('viewBank', $bankChangeRequest->bankAccount->supplier);

        return response()->json([
            'change_request' => $bankChangeRequest->toApiArray(),
        ]);
    }

    public function approve(Request $request, BankChangeRequest $bankChangeRequest): JsonResponse
    {
        $this->authorize('approveBankChange', $bankChangeRequest->bankAccount->supplier);

        try {
            $bankChangeRequest->approve($this->optionalReason($request));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'change_request' => $bankChangeRequest->fresh()->toApiArray(),
            'bank_account' => $bankChangeRequest->bankAccount()->with('history')->firstOrFail()->toApiArray(),
        ]);
    }

    public function reject(Request $request, BankChangeRequest $bankChangeRequest): JsonResponse
    {
        $this->authorize('approveBankChange', $bankChangeRequest->bankAccount->supplier);

        try {
            $bankChangeRequest->reject($this->optionalReason($request));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'change_request' => $bankChangeRequest->fresh()->toApiArray(),
            'bank_account' => $bankChangeRequest->bankAccount()->firstOrFail()->toApiArray(),
        ]);
    }

    private function optionalReason(Request $request): ?string
    {
        $reason = $request->input('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
