<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuotationItemRequest;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Requests\UpdateQuotationItemRequest;
use App\Http\Requests\UpdateQuotationRequest;
use App\Models\AuditLog;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\RfqDistribution;
use App\Services\AuditLogger;
use App\Services\QuotationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierQuotationController extends Controller
{
    use AuthorizesRequests;

    public function store(
        StoreQuotationRequest $request,
        RfqDistribution $distribution,
        QuotationService $quotations,
    ): JsonResponse {
        Gate::authorize('createForDistribution', [Quotation::class, $distribution]);

        $quotation = $quotations->createForDistribution(
            $distribution,
            (int) $request->user()->company_id,
            $request->safe()->except([
                'company_id',
                'supplier_id',
                'supplier_company_id',
                'tenant_id',
                'rfq_id',
                'rfq_distribution_id',
                'status',
                'subtotal',
                'total',
                'line_total',
            ])
        );

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_CREATED,
            $quotation,
            $quotation->supplier_company_id,
            after: $quotation->toApiArray(includeSupplier: false),
        );

        return response()->json([
            'quotation' => $quotation->toApiArray(includeSupplier: false),
        ], 201);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        Gate::authorize('view', $quotation);
        $quotation->refreshExpiration();

        return response()->json([
            'quotation' => $quotation->fresh(['items', 'supplierCompany.supplierProfile'])->toApiArray(includeSupplier: false),
        ]);
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('update', $quotation);

        $before = $quotation->load('items')->toApiArray(includeSupplier: false);
        $quotation = $quotations->updateDraft(
            $quotation,
            $request->safe()->except([
                'company_id',
                'supplier_id',
                'supplier_company_id',
                'tenant_id',
                'status',
                'subtotal',
                'total',
            ])
        );

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_UPDATED,
            $quotation,
            $quotation->supplier_company_id,
            before: $before,
            after: $quotation->toApiArray(includeSupplier: false),
        );

        return response()->json([
            'quotation' => $quotation->toApiArray(includeSupplier: false),
        ]);
    }

    public function destroy(Quotation $quotation, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('delete', $quotation);

        $before = $quotation->load('items')->toApiArray(includeSupplier: false);
        $companyId = $quotation->supplier_company_id;
        $quotations->deleteDraft($quotation);

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_DELETED,
            $quotation,
            $companyId,
            before: $before,
            after: ['id' => $before['id'], 'deleted' => true],
        );

        return response()->json([
            'message' => 'Quotation deleted.',
        ]);
    }

    public function submit(Request $request, Quotation $quotation, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('submit', $quotation);

        $before = $quotation->load('items')->toApiArray(includeSupplier: false);
        $quotation = $quotations->submit($quotation);

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_SUBMITTED,
            $quotation,
            $quotation->supplier_company_id,
            before: $before,
            after: $quotation->toApiArray(includeSupplier: false),
            reason: $request->input('reason'),
        );

        return response()->json([
            'quotation' => $quotation->toApiArray(includeSupplier: false),
        ]);
    }

    public function withdraw(Request $request, Quotation $quotation, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('withdraw', $quotation);

        $before = $quotation->load('items')->toApiArray(includeSupplier: false);
        $quotation = $quotations->withdraw($quotation);

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_WITHDRAWN,
            $quotation,
            $quotation->supplier_company_id,
            before: $before,
            after: $quotation->toApiArray(includeSupplier: false),
            reason: $request->input('reason'),
        );

        return response()->json([
            'quotation' => $quotation->toApiArray(includeSupplier: false),
        ]);
    }

    public function storeItem(StoreQuotationItemRequest $request, Quotation $quotation, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('update', $quotation);

        $item = $quotations->createItem($quotation, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_ITEM_CREATED,
            $item,
            $quotation->supplier_company_id,
            after: $item->toApiArray(),
        );

        return response()->json([
            'quotation_item' => $item->toApiArray(),
            'quotation' => $quotation->fresh(['items'])->toApiArray(includeSupplier: false),
        ], 201);
    }

    public function updateItem(
        UpdateQuotationItemRequest $request,
        Quotation $quotation,
        QuotationItem $item,
        QuotationService $quotations,
    ): JsonResponse {
        Gate::authorize('update', $quotation);
        $this->assertItemBelongs($quotation, $item);

        $before = $item->toApiArray();
        $item = $quotations->updateItem($item, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_ITEM_UPDATED,
            $item,
            $quotation->supplier_company_id,
            before: $before,
            after: $item->toApiArray(),
        );

        return response()->json([
            'quotation_item' => $item->toApiArray(),
            'quotation' => $quotation->fresh(['items'])->toApiArray(includeSupplier: false),
        ]);
    }

    public function destroyItem(Quotation $quotation, QuotationItem $item, QuotationService $quotations): JsonResponse
    {
        Gate::authorize('update', $quotation);
        $this->assertItemBelongs($quotation, $item);

        $before = $item->toApiArray();
        $quotations->deleteItem($item);

        app(AuditLogger::class)->record(
            AuditLog::QUOTATION_ITEM_DELETED,
            $item,
            $quotation->supplier_company_id,
            before: $before,
            after: ['id' => $before['id'], 'deleted' => true],
        );

        return response()->json([
            'message' => 'Quotation item deleted.',
            'quotation' => $quotation->fresh(['items'])->toApiArray(includeSupplier: false),
        ]);
    }

    private function assertItemBelongs(Quotation $quotation, QuotationItem $item): void
    {
        if ((int) $item->quotation_id !== (int) $quotation->id) {
            abort(404);
        }
    }
}
