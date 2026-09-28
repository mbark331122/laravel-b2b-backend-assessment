<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyAddressRequest;
use App\Http\Requests\UpdateCompanyAddressRequest;
use App\Models\CompanyAddress;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyAddressController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyAddress::class);

        $query = CompanyAddress::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('type')
            ->orderBy('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'company_addresses',
            fn (CompanyAddress $address) => $address->toApiArray(),
        );
    }

    public function store(StoreCompanyAddressRequest $request, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('create', CompanyAddress::class);

        $address = $service->createAddress($request->user()->company, $request->validated());

        return response()->json([
            'company_address' => $address->toApiArray(),
        ], 201);
    }

    public function show(CompanyAddress $companyAddress): JsonResponse
    {
        $this->authorize('view', $companyAddress);

        return response()->json([
            'company_address' => $companyAddress->toApiArray(),
        ]);
    }

    public function update(
        UpdateCompanyAddressRequest $request,
        CompanyAddress $companyAddress,
        CompanyOnboardingService $service,
    ): JsonResponse {
        $this->authorize('update', $companyAddress);

        $address = $service->updateAddress($companyAddress, $request->validated());

        return response()->json([
            'company_address' => $address->toApiArray(),
        ]);
    }

    public function destroy(CompanyAddress $companyAddress, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('delete', $companyAddress);

        $service->deleteAddress($companyAddress);

        return response()->json([
            'message' => 'Address deleted.',
        ]);
    }
}
