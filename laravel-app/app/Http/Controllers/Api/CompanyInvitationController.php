<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptCompanyInvitationRequest;
use App\Http\Requests\StoreCompanyInvitationRequest;
use App\Models\AuditLog;
use App\Models\CompanyInvitation;
use App\Services\AuditLogger;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyInvitationController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyInvitation::class);

        $query = CompanyInvitation::query()
            ->where('company_id', $request->user()->company_id)
            ->with('role')
            ->orderByDesc('id');

        $invitations = (clone $query)->get();
        foreach ($invitations as $invitation) {
            if ($invitation->refreshExpiration()) {
                app(AuditLogger::class)->record(
                    AuditLog::COMPANY_INVITATION_EXPIRED,
                    $invitation,
                    $invitation->company_id,
                    after: $invitation->toApiArray(),
                );
            }
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'company_invitations',
            function (CompanyInvitation $invitation) {
                $invitation->refreshExpiration();

                return $invitation->toApiArray();
            },
        );
    }

    public function store(StoreCompanyInvitationRequest $request, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('create', CompanyInvitation::class);

        $issued = $service->createInvitation(
            $request->user(),
            $request->user()->company,
            $request->validated('email'),
            $request->validated('role'),
            $request->validated('expires_in_days'),
        );

        // Plain token is returned once at creation only — never stored or listed again.
        return response()->json([
            'company_invitation' => $issued['invitation']->toApiArray(),
            'invitation_token' => $issued['plain_token'],
        ], 201);
    }

    public function show(CompanyInvitation $companyInvitation): JsonResponse
    {
        $this->authorize('view', $companyInvitation);

        if ($companyInvitation->refreshExpiration()) {
            app(AuditLogger::class)->record(
                AuditLog::COMPANY_INVITATION_EXPIRED,
                $companyInvitation,
                $companyInvitation->company_id,
                after: $companyInvitation->toApiArray(),
            );
        }

        return response()->json([
            'company_invitation' => $companyInvitation->fresh()->toApiArray(),
        ]);
    }

    public function revoke(CompanyInvitation $companyInvitation, CompanyOnboardingService $service): JsonResponse
    {
        $this->authorize('revoke', $companyInvitation);

        $invitation = $service->revokeInvitation($companyInvitation);

        return response()->json([
            'company_invitation' => $invitation->toApiArray(),
        ]);
    }

    public function accept(AcceptCompanyInvitationRequest $request, CompanyOnboardingService $service): JsonResponse
    {
        $result = $service->acceptInvitation(
            $request->validated('token'),
            $request->validated(),
        );

        return response()->json([
            'token' => $result['token'],
            'user' => $result['user']->toApiArray(),
        ], 201);
    }
}
