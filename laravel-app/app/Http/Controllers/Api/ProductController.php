<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with(['category', 'supplierProfile'])
            ->visibleTo($request->user())
            ->latest('id')
            ->get()
            ->map(fn (Product $product) => $product->toApiArray())
            ->values();

        return response()->json([
            'products' => $products,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $user = $request->user();
        $company = $user->company;
        $profile = $company->supplierProfile;

        $product = new Product($request->validated());
        $product->company()->associate($company);
        $product->supplierProfile()->associate($profile);
        $product->status = $request->validated('status', Product::STATUS_ACTIVE);
        $product->save();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_CREATED,
            $product,
            $product->company_id,
            after: $product->toApiArray(),
        );

        return response()->json([
            'product' => $product->fresh(['category', 'supplierProfile'])->toApiArray(),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json([
            'product' => $product->load(['category', 'supplierProfile'])->toApiArray(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $before = $product->toApiArray();
        $product->fill($request->validated());
        $product->save();
        $after = $product->fresh(['category', 'supplierProfile'])->toApiArray();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_UPDATED,
            $product,
            $product->company_id,
            before: $before,
            after: $after,
        );

        return response()->json([
            'product' => $after,
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $before = $product->toApiArray();
        $companyId = $product->company_id;
        $productId = $product->id;
        $product->delete();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_DELETED,
            $product,
            $companyId,
            before: $before,
            after: ['id' => $productId, 'deleted' => true],
        );

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }
}
