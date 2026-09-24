<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\ProductCategory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission(Permission::PRODUCT_READ)) {
            abort(403);
        }

        $categories = ProductCategory::query()
            ->where('status', ProductCategory::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category) => $category->toApiArray())
            ->values();

        return response()->json([
            'product_categories' => $categories,
        ]);
    }
}
