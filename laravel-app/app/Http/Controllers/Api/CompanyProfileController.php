<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertCompanyProfileRequest;
use App\Models\CompanyProfile;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewOwn', CompanyProfile::class);

        $company = $request->user()->company;
        $profile = $company->profile()->first();

        return response()->json([
            'company' => $company->toApiArray(),
            'company_profile' => $profile?->toApiArray() ?? [
                'id' => null,
                'company_id' => $company->id,
                'legal_name' => null,
                'business_description' => null,
                'registration_number' => null,
                'tax_identifier' => null,
                'website' => null,
                'primary_email' => null,
                'primary_phone' => null,
                'updated_at' => null,
            ],
        ]);
    }

    public function upsert(UpsertCompanyProfileRequest $request, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('upsert', CompanyProfile::class);

        $profile = $service->upsertProfile($request->user()->company, $request->validated());

        return response()->json([
            'company' => $request->user()->company->toApiArray(),
            'company_profile' => $profile->toApiArray(),
        ]);
    }
}
