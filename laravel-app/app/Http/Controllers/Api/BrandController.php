<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Permission;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission(Permission::PRODUCT_READ)) {
            abort(403);
        }

        $brands = Brand::query()
            ->where('status', Brand::STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => $brand->toApiArray())
            ->values();

        return response()->json([
            'brands' => $brands,
        ]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        if (! $request->user()->hasPermission(Permission::BRAND_CREATE)) {
            abort(403);
        }

        $brand = new Brand($request->validated());
        $brand->status = $request->validated('status', Brand::STATUS_ACTIVE);
        $brand->save();

        app(AuditLogger::class)->record(
            AuditLog::BRAND_CREATED,
            $brand,
            null,
            after: $brand->toApiArray(),
        );

        return response()->json([
            'brand' => $brand->toApiArray(),
        ], 201);
    }
}
