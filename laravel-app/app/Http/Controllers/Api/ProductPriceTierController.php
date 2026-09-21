<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncProductPriceTiersRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\ProductCatalogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class ProductPriceTierController extends Controller
{
    use AuthorizesRequests;

    public function index(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json([
            'price_tiers' => $product->priceTiers()->get()->map->toApiArray()->values(),
        ]);
    }

    public function sync(SyncProductPriceTiersRequest $request, Product $product, ProductCatalogService $catalog): JsonResponse
    {
        $this->authorize('update', $product);

        $before = $product->priceTiers()->get()->map->toApiArray()->values()->all();
        $catalog->syncPriceTiers($product, $request->validated('tiers'));
        $after = $product->priceTiers()->get()->map->toApiArray()->values()->all();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_PRICE_TIERS_UPDATED,
            $product,
            $product->company_id,
            before: ['price_tiers' => $before],
            after: ['price_tiers' => $after],
        );

        return response()->json([
            'price_tiers' => $after,
        ]);
    }
}
