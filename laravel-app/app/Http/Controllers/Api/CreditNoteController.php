<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreditNoteRequest;
use App\Http\Requests\VoidCreditNoteRequest;
use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Rma;
use App\Services\AuditLogger;
use App\Services\CreditNoteService;
use App\Services\DomainNotificationPublisher;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreditNoteController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CreditNote::class);

        $user = $request->user();
        $query = CreditNote::query()
            ->with([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'credit_notes',
            fn (CreditNote $creditNote) => $creditNote->toApiArray(),
        );
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CreditNote::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $creditNotes = CreditNote::query()
            ->with([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CreditNote $creditNote) => $creditNote->toApiArray())
            ->values();

        return response()->json([
            'credit_notes' => $creditNotes,
        ]);
    }

    public function show(CreditNote $creditNote): JsonResponse
    {
        Gate::authorize('view', $creditNote);

        return response()->json([
            'credit_note' => $creditNote->load([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function showForRma(Rma $rma): JsonResponse
    {
        $creditNote = CreditNote::query()
            ->where('rma_id', $rma->id)
            ->firstOrFail();

        Gate::authorize('view', $creditNote);

        return response()->json([
            'credit_note' => $creditNote->load([
                'items',
                'rma',
                'invoice',
                'purchaseOrder',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreCreditNoteRequest $request,
        Rma $rma,
        CreditNoteService $creditNotes,
    ): JsonResponse {
        Gate::authorize('createFromRma', [CreditNote::class, $rma]);

        $result = $creditNotes->createFromClosedRma(
            $rma,
            $request->user(),
            $request->validated('reason'),
        );
        $creditNote = $result['credit_note'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::CREDIT_NOTE_CREATED,
                $creditNote,
                $creditNote->supplier_company_id,
                after: $creditNote->toApiArray(),
            );
        }

        return response()->json([
            'credit_note' => $creditNote->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function issue(Request $request, CreditNote $creditNote, CreditNoteService $creditNotes): JsonResponse
    {
        Gate::authorize('issue', $creditNote);

        $before = $creditNote->toApiArray();
        $creditNote = $creditNotes->issue($creditNote);

        app(AuditLogger::class)->record(
            AuditLog::CREDIT_NOTE_ISSUED,
            $creditNote,
            $creditNote->supplier_company_id,
            before: $before,
            after: $creditNote->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->creditNoteIssued($creditNote);

        return response()->json([
            'credit_note' => $creditNote->toApiArray(),
        ]);
    }

    public function cancel(Request $request, CreditNote $creditNote, CreditNoteService $creditNotes): JsonResponse
    {
        Gate::authorize('cancel', $creditNote);

        $before = $creditNote->toApiArray();
        $creditNote = $creditNotes->cancel($creditNote, $request->input('reason'));

        app(AuditLogger::class)->record(
            AuditLog::CREDIT_NOTE_CANCELLED,
            $creditNote,
            $creditNote->supplier_company_id,
            before: $before,
            after: $creditNote->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->creditNoteCancelled($creditNote);

        return response()->json([
            'credit_note' => $creditNote->toApiArray(),
        ]);
    }

    public function void(VoidCreditNoteRequest $request, CreditNote $creditNote, CreditNoteService $creditNotes): JsonResponse
    {
        Gate::authorize('void', $creditNote);

        $before = $creditNote->toApiArray();
        $creditNote = $creditNotes->void($creditNote, (string) $request->validated('reason'));

        app(AuditLogger::class)->record(
            AuditLog::CREDIT_NOTE_VOIDED,
            $creditNote,
            $creditNote->supplier_company_id,
            before: $before,
            after: $creditNote->toApiArray(),
            reason: $request->validated('reason'),
        );

        app(DomainNotificationPublisher::class)->creditNoteVoided($creditNote);

        return response()->json([
            'credit_note' => $creditNote->toApiArray(),
        ]);
    }
}
