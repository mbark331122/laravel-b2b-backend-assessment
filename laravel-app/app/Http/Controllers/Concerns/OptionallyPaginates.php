<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Optional list pagination foundation.
 *
 * When `per_page` is absent or zero, responses keep the existing unbounded
 * collection contract (Sprint 1–16 compatibility).
 * When `per_page` is 1–100, returns the same resource key plus `meta`.
 */
trait OptionallyPaginates
{
    /**
     * @param  callable(mixed): mixed  $mapper
     */
    protected function optionallyPaginate(
        Builder $query,
        Request $request,
        string $resourceKey,
        callable $mapper,
    ): JsonResponse {
        $perPage = (int) $request->query('per_page', 0);

        if ($perPage < 1) {
            $items = $query->get()->map($mapper)->values();

            return response()->json([
                $resourceKey => $items,
            ]);
        }

        $perPage = min($perPage, 100);
        $page = $query->paginate($perPage);

        return response()->json([
            $resourceKey => Collection::make($page->items())->map($mapper)->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
