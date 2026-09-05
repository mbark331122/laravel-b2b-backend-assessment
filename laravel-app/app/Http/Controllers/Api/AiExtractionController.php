<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiExtractionRequest;
use App\Models\Rfq;
use App\Services\AiExtractionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AiExtractionController extends Controller
{
    use AuthorizesRequests;

    public function index(Rfq $rfq): JsonResponse
    {
        $this->authorize('view', $rfq);

        $extractions = $rfq->aiExtractions()
            ->with('proposals')
            ->latest('id')
            ->get()
            ->map(fn ($extraction) => $extraction->toApiArray())
            ->values();

        return response()->json([
            'extractions' => $extractions,
        ]);
    }

    public function store(StoreAiExtractionRequest $request, Rfq $rfq, AiExtractionService $extractions): JsonResponse
    {
        $this->authorize('view', $rfq);

        $extraction = $extractions->extractForRfq($rfq, $request->validated('text'));

        return response()->json([
            'extraction' => $extraction->toApiArray(),
            'rfq' => $rfq->fresh()->toApiArray(),
        ], 201);
    }
}
