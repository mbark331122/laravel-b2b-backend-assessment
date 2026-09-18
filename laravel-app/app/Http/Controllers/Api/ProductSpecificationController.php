<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncProductSpecificationsRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\ProductCatalogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class ProductSpecificationController extends Controller
{
    use AuthorizesRequests;

    public function index(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json([
            'specifications' => $product->specifications()->get()->map->toApiArray()->values(),
        ]);
    }

    public function sync(SyncProductSpecificationsRequest $request, Product $product, ProductCatalogService $catalog): JsonResponse
    {
        $this->authorize('update', $product);

        $before = $product->specifications()->get()->map->toApiArray()->values()->all();
        $catalog->syncSpecifications($product, $request->validated('specifications'));
        $after = $product->specifications()->get()->map->toApiArray()->values()->all();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_SPECIFICATIONS_UPDATED,
            $product,
            $product->company_id,
            before: ['specifications' => $before],
            after: ['specifications' => $after],
        );

        return response()->json([
            'specifications' => $after,
        ]);
    }
}
