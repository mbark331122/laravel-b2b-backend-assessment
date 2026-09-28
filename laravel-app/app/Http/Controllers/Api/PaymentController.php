<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\DomainNotificationPublisher;
use App\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $user = $request->user();
        $query = Payment::query()
            ->with(['invoice', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'payments',
            fn (Payment $payment) => $payment->toApiArray(),
        );
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $payments = Payment::query()
            ->with(['invoice', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => $payment->toApiArray())
            ->values();

        return response()->json([
            'payments' => $payments,
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        Gate::authorize('view', $payment);

        return response()->json([
            'payment' => $payment->load([
                'invoice',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StorePaymentRequest $request,
        Invoice $invoice,
        PaymentService $payments,
    ): JsonResponse {
        Gate::authorize('createForInvoice', [Payment::class, $invoice]);

        $payment = $payments->createForIssuedInvoice(
            $invoice,
            $request->user(),
            (string) $request->validated('method'),
            $request->validated('notes'),
        );

        app(AuditLogger::class)->record(
            AuditLog::PAYMENT_CREATED,
            $payment,
            $payment->buyer_company_id,
            after: $payment->toApiArray(),
        );

        app(DomainNotificationPublisher::class)->paymentCreated($payment);

        return response()->json([
            'payment' => $payment->toApiArray(),
        ], 201);
    }

    public function markPaid(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::authorize('markPaid', $payment);

        $before = $payment->toApiArray();
        $payment = $payments->markPaid($payment);

        app(AuditLogger::class)->record(
            AuditLog::PAYMENT_MARKED_PAID,
            $payment,
            $payment->supplier_company_id,
            before: $before,
            after: $payment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->paymentMarkedPaid($payment);

        return response()->json([
            'payment' => $payment->toApiArray(),
        ]);
    }

    public function markFailed(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::authorize('markFailed', $payment);

        $before = $payment->toApiArray();
        $payment = $payments->markFailed($payment);

        app(AuditLogger::class)->record(
            AuditLog::PAYMENT_MARKED_FAILED,
            $payment,
            $payment->supplier_company_id,
            before: $before,
            after: $payment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->paymentMarkedFailed($payment);

        return response()->json([
            'payment' => $payment->toApiArray(),
        ]);
    }

    public function cancel(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::authorize('cancel', $payment);

        $before = $payment->toApiArray();
        $payment = $payments->cancel($payment);

        app(AuditLogger::class)->record(
            AuditLog::PAYMENT_CANCELLED,
            $payment,
            $payment->buyer_company_id,
            before: $before,
            after: $payment->toApiArray(),
            reason: $request->input('reason'),
        );

        app(DomainNotificationPublisher::class)->paymentCancelled($payment);

        return response()->json([
            'payment' => $payment->toApiArray(),
        ]);
    }
}
