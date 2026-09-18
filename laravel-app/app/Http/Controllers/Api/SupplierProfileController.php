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

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplierProfile::class);

        $query = SupplierProfile::query()->visibleTo($request->user());

        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('display_name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if ($request->filled('status') && ($request->user()->isAdmin() || $request->user()->isSupplierUser())) {
            $query->where('status', (string) $request->query('status'));
        }

        $profiles = $query->orderBy('display_name')->orderBy('id')->get()
            ->map(fn (SupplierProfile $profile) => $profile->toApiArray())
            ->values();

        return response()->json([
            'supplier_profiles' => $profiles,
        ]);
    }

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
