<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyMemberController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyCompanyMember');

        $query = User::query()
            ->where('company_id', $request->user()->company_id)
            ->with('role')
            ->orderBy('name')
            ->orderBy('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'company_members',
            fn (User $member) => $member->toMemberApiArray(),
        );
    }

    public function show(Request $request, User $member): JsonResponse
    {
        Gate::authorize('viewCompanyMember', $member);

        return response()->json([
            'company_member' => $member->toMemberApiArray(),
        ]);
    }

    public function activate(Request $request, User $member, CompanyOnboardingService $service): JsonResponse
    {
        Gate::authorize('manageCompanyMember', $member);

        $member = $service->activateMember($request->user(), $member);

        return response()->json([
            'company_member' => $member->toMemberApiArray(),
        ]);
    }

    public function deactivate(Request $request, User $member, CompanyOnboardingService $service): JsonResponse
    {
        Gate::authorize('manageCompanyMember', $member);

        $member = $service->deactivateMember($request->user(), $member);

        return response()->json([
            'company_member' => $member->toMemberApiArray(),
        ]);
    }
}
