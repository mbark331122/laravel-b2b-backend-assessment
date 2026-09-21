<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Refund;
use App\Services\AuditLogger;
use App\Services\RefundService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RefundController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Refund::class);

        $user = $request->user();
        $query = Refund::query()
            ->with([
                'creditNote',
                'invoice',
                'payment',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return response()->json([
            'refunds' => $query->get()->map(fn (Refund $refund) => $refund->toApiArray())->values(),
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Refund::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $refunds = Refund::query()
            ->with([
                'creditNote',
                'invoice',
                'payment',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Refund $refund) => $refund->toApiArray())
            ->values();

        return response()->json([
            'refunds' => $refunds,
        ]);
    }

    public function show(Refund $refund): JsonResponse
    {
        Gate::authorize('view', $refund);

        return response()->json([
            'refund' => $refund->load([
                'creditNote',
                'invoice',
                'payment',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreRefundRequest $request,
        CreditNote $creditNote,
        RefundService $refunds,
    ): JsonResponse {
        Gate::authorize('createFromCreditNote', [Refund::class, $creditNote]);

        $result = $refunds->createFromIssuedCreditNote(
            $creditNote,
            $request->user(),
            $request->validated('method'),
            $request->validated('reason'),
        );
        $refund = $result['refund'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::REFUND_CREATED,
                $refund,
                $refund->supplier_company_id,
                after: $refund->toApiArray(),
            );
        }

        return response()->json([
            'refund' => $refund->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function process(Request $request, Refund $refund, RefundService $refunds): JsonResponse
    {
        Gate::authorize('process', $refund);

        $before = $refund->toApiArray();
        $refund = $refunds->process($refund);

        app(AuditLogger::class)->record(
            AuditLog::REFUND_PROCESSED,
            $refund,
            $refund->supplier_company_id,
            before: $before,
            after: $refund->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'refund' => $refund->toApiArray(),
        ]);
    }

    public function fail(Request $request, Refund $refund, RefundService $refunds): JsonResponse
    {
        Gate::authorize('fail', $refund);

        $before = $refund->toApiArray();
        $refund = $refunds->fail($refund);

        app(AuditLogger::class)->record(
            AuditLog::REFUND_FAILED,
            $refund,
            $refund->supplier_company_id,
            before: $before,
            after: $refund->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'refund' => $refund->toApiArray(),
        ]);
    }

    public function cancel(Request $request, Refund $refund, RefundService $refunds): JsonResponse
    {
        Gate::authorize('cancel', $refund);

        $before = $refund->toApiArray();
        $refund = $refunds->cancel($refund);

        app(AuditLogger::class)->record(
            AuditLog::REFUND_CANCELLED,
            $refund,
            $refund->supplier_company_id,
            before: $before,
            after: $refund->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'refund' => $refund->toApiArray(),
        ]);
    }
}
