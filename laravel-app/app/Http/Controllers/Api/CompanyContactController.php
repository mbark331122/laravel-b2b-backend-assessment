<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyContactRequest;
use App\Http\Requests\UpdateCompanyContactRequest;
use App\Models\CompanyContact;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyContactController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyContact::class);

        $query = CompanyContact::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->orderBy('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'company_contacts',
            fn (CompanyContact $contact) => $contact->toApiArray(),
        );
    }

    public function store(StoreCompanyContactRequest $request, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('create', CompanyContact::class);

        $contact = $service->createContact($request->user()->company, $request->validated());

        return response()->json([
            'company_contact' => $contact->toApiArray(),
        ], 201);
    }

    public function show(CompanyContact $companyContact): JsonResponse
    {
        $this->authorize('view', $companyContact);

        return response()->json([
            'company_contact' => $companyContact->toApiArray(),
        ]);
    }

    public function update(
        UpdateCompanyContactRequest $request,
        CompanyContact $companyContact,
        CompanyOnboardingService $service,
    ): JsonResponse {
        $this->authorize('update', $companyContact);

        $contact = $service->updateContact($companyContact, $request->validated());

        return response()->json([
            'company_contact' => $contact->toApiArray(),
        ]);
    }

    public function destroy(CompanyContact $companyContact, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('delete', $companyContact);

        $service->deleteContact($companyContact);

        return response()->json([
            'message' => 'Contact deleted.',
        ]);
    }
}
