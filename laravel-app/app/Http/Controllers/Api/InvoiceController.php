<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\AuditLogger;
use App\Services\InvoiceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $user = $request->user();
        $query = Invoice::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->visibleTo($user)
            ->orderByDesc('id');

        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            $query->where('buyer_company_id', $user->company_id);
        }

        return response()->json([
            'invoices' => $query->get()->map(fn (Invoice $invoice) => $invoice->toApiArray())->values(),
        ]);
    }

    public function indexForSupplier(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupplierUser()) {
            abort(403);
        }

        $invoices = Invoice::query()
            ->with(['items', 'buyerCompany', 'supplierCompany.supplierProfile'])
            ->where('supplier_company_id', $user->company_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $invoice) => $invoice->toApiArray())
            ->values();

        return response()->json([
            'invoices' => $invoices,
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

        return response()->json([
            'invoice' => $invoice->load([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function showForPurchaseOrder(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $invoice = Invoice::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->firstOrFail();

        Gate::authorize('view', $invoice);

        return response()->json([
            'invoice' => $invoice->load([
                'items',
                'buyerCompany',
                'supplierCompany.supplierProfile',
            ])->toApiArray(),
        ]);
    }

    public function store(
        StoreInvoiceRequest $request,
        PurchaseOrder $purchaseOrder,
        InvoiceService $invoices,
    ): JsonResponse {
        Gate::authorize('createFromPurchaseOrder', [Invoice::class, $purchaseOrder]);

        $result = $invoices->createFromConfirmedPurchaseOrder($purchaseOrder, $request->user());
        $invoice = $result['invoice'];

        if ($result['created']) {
            app(AuditLogger::class)->record(
                AuditLog::INVOICE_CREATED,
                $invoice,
                $invoice->supplier_company_id,
                after: $invoice->toApiArray(),
            );
        }

        return response()->json([
            'invoice' => $invoice->toApiArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        Gate::authorize('issue', $invoice);

        $before = $invoice->toApiArray();
        $invoice = $invoices->issue($invoice);

        app(AuditLogger::class)->record(
            AuditLog::INVOICE_ISSUED,
            $invoice,
            $invoice->supplier_company_id,
            before: $before,
            after: $invoice->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'invoice' => $invoice->toApiArray(),
        ]);
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        Gate::authorize('cancel', $invoice);

        $before = $invoice->toApiArray();
        $invoice = $invoices->cancel($invoice, $request->input('reason'));

        app(AuditLogger::class)->record(
            AuditLog::INVOICE_CANCELLED,
            $invoice,
            $invoice->supplier_company_id,
            before: $before,
            after: $invoice->toApiArray(),
            reason: $request->input('reason'),
        );

        return response()->json([
            'invoice' => $invoice->toApiArray(),
        ]);
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        Gate::authorize('void', $invoice);

        $before = $invoice->toApiArray();
        $invoice = $invoices->void($invoice, (string) $request->validated('reason'));

        app(AuditLogger::class)->record(
            AuditLog::INVOICE_VOIDED,
            $invoice,
            $invoice->supplier_company_id,
            before: $before,
            after: $invoice->toApiArray(),
            reason: $request->validated('reason'),
        );

        return response()->json([
            'invoice' => $invoice->toApiArray(),
        ]);
    }
}
