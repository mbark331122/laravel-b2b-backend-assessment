<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRfqItemRequest;
use App\Http\Requests\UpdateRfqItemRequest;
use App\Models\AuditLog;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Services\AuditLogger;
use App\Services\RfqService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class RfqItemController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreRfqItemRequest $request, Rfq $rfq, RfqService $rfqService): JsonResponse
    {
        $this->authorize('update', $rfq);

        $item = $rfqService->createItem($rfq, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::RFQ_ITEM_CREATED,
            $item,
            $rfq->company_id,
            after: $item->toApiArray(),
        );

        return response()->json([
            'rfq_item' => $item->toApiArray(),
            'rfq' => $rfq->fresh('items')->toApiArray(),
        ], 201);
    }

    public function update(UpdateRfqItemRequest $request, Rfq $rfq, RfqItem $item, RfqService $rfqService): JsonResponse
    {
        $this->authorize('update', $rfq);
        $this->assertItemBelongsToRfq($rfq, $item);

        $before = $item->toApiArray();
        $item = $rfqService->updateItem($item, $request->validated());

        app(AuditLogger::class)->record(
            AuditLog::RFQ_ITEM_UPDATED,
            $item,
            $rfq->company_id,
            before: $before,
            after: $item->toApiArray(),
        );

        return response()->json([
            'rfq_item' => $item->toApiArray(),
            'rfq' => $rfq->fresh('items')->toApiArray(),
        ]);
    }

    public function destroy(Rfq $rfq, RfqItem $item, RfqService $rfqService): JsonResponse
    {
        $this->authorize('update', $rfq);
        $this->assertItemBelongsToRfq($rfq, $item);

        $before = $item->toApiArray();
        $rfqService->deleteItem($item);

        app(AuditLogger::class)->record(
            AuditLog::RFQ_ITEM_DELETED,
            $item,
            $rfq->company_id,
            before: $before,
            after: ['id' => $before['id'], 'deleted' => true],
        );

        return response()->json([
            'message' => 'RFQ item deleted.',
            'rfq' => $rfq->fresh('items')->toApiArray(),
        ]);
    }

    private function assertItemBelongsToRfq(Rfq $rfq, RfqItem $item): void
    {
        if ((int) $item->rfq_id !== (int) $rfq->id) {
            abort(404);
        }
    }
}
