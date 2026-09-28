<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\TransitionProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProductController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $user = $request->user();
        $filters = $request->only([
            'q',
            'product_category_id',
            'brand_id',
            'supplier_profile_id',
            'company_id',
            'currency',
            'min_moq',
            'max_moq',
            'status',
        ]);

        // Buyers cannot override publication visibility via status filter.
        if ($user->isBuyerUser() && ! $user->isAdmin()) {
            unset($filters['status']);
        }

        $query = Product::query()
            ->with(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers'])
            ->visibleTo($user)
            ->applyCatalogFilters($filters)
            ->orderBy('name')
            ->orderBy('id');

        return $this->optionallyPaginate(
            $query,
            $request,
            'products',
            fn (Product $product) => $product->toApiArray(),
        );
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
        $product->quantity_increment = $request->validated('quantity_increment', 1);
        // Suppliers cannot publish directly — always start in draft.
        $product->status = Product::STATUS_DRAFT;
        $product->save();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_CREATED,
            $product,
            $product->company_id,
            after: $product->toApiArray(),
        );

        return response()->json([
            'product' => $product->fresh(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers'])->toApiArray(),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return response()->json([
            'product' => $product->load(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers'])->toApiArray(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $before = $product->toApiArray();
        $product->fill($request->validated());
        $product->save();
        $after = $product->fresh(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers'])->toApiArray();

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

    public function transition(TransitionProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('transition', $product);

        $before = $product->toApiArray();
        $to = $request->validated('status');

        try {
            $product->transitionTo($to, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $after = $product->fresh(['category', 'supplierProfile', 'brand', 'specifications', 'priceTiers'])->toApiArray();

        app(AuditLogger::class)->record(
            AuditLog::PRODUCT_TRANSITIONED,
            $product,
            $product->company_id,
            before: $before,
            after: $after,
            reason: $request->validated('reason'),
        );

        return response()->json([
            'product' => $after,
        ]);
    }
}
