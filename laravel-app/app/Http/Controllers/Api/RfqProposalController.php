<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RfqProposal;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RfqProposalController extends Controller
{
    use AuthorizesRequests;

    public function approve(Request $request, RfqProposal $proposal): JsonResponse
    {
        $this->authorize('approve', $proposal->rfq);

        try {
            $proposal->approve($this->optionalReason($request));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'proposal' => $proposal->fresh()->toApiArray(),
            'rfq' => $proposal->rfq()->firstOrFail()->toApiArray(),
        ]);
    }

    public function reject(Request $request, RfqProposal $proposal): JsonResponse
    {
        $this->authorize('approve', $proposal->rfq);

        try {
            $proposal->reject($this->optionalReason($request));
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json([
            'proposal' => $proposal->fresh()->toApiArray(),
            'rfq' => $proposal->rfq()->firstOrFail()->toApiArray(),
        ]);
    }

    private function optionalReason(Request $request): ?string
    {
        $reason = $request->input('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
