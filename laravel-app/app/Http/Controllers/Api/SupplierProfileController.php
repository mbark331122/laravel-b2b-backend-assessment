<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierProfileRequest;
use App\Http\Requests\UpdateSupplierProfileRequest;
use App\Models\AuditLog;
use App\Models\SupplierProfile;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierProfileController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->company?->supplierProfile;

        if ($profile === null) {
            abort(404);
        }

        $this->authorize('view', $profile);

        return response()->json([
            'supplier_profile' => $profile->toApiArray(),
        ]);
    }

    public function showById(SupplierProfile $supplierProfile): JsonResponse
    {
        $this->authorize('view', $supplierProfile);

        return response()->json([
            'supplier_profile' => $supplierProfile->toApiArray(),
        ]);
    }

    public function store(StoreSupplierProfileRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierProfile::class);

        $company = $request->user()->company;

        $profile = new SupplierProfile($request->validated());
        $profile->company()->associate($company);
        $profile->status = $request->validated('status', SupplierProfile::STATUS_ACTIVE);
        $profile->save();

        app(AuditLogger::class)->record(
            AuditLog::SUPPLIER_PROFILE_CREATED,
            $profile,
            $profile->company_id,
            after: $profile->toApiArray(),
        );

        return response()->json([
            'supplier_profile' => $profile->toApiArray(),
        ], 201);
    }

    public function update(UpdateSupplierProfileRequest $request, SupplierProfile $supplierProfile): JsonResponse
    {
        $this->authorize('update', $supplierProfile);

        $before = $supplierProfile->toApiArray();
        $supplierProfile->fill($request->validated());
        $supplierProfile->save();
        $after = $supplierProfile->fresh()->toApiArray();

        app(AuditLogger::class)->record(
            AuditLog::SUPPLIER_PROFILE_UPDATED,
            $supplierProfile,
            $supplierProfile->company_id,
            before: $before,
            after: $after,
        );

        return response()->json([
            'supplier_profile' => $after,
        ]);
    }
}
