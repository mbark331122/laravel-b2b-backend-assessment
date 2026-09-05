<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class SupplierBankAccountController extends Controller
{
    use AuthorizesRequests;

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('viewBank', $supplier);

        $account = $supplier->bankAccount()->with(['history', 'changeRequests'])->firstOrFail();

        return response()->json([
            'bank_account' => $account->toApiArray(),
        ]);
    }
}
