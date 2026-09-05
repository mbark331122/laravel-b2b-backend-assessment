<?php

use App\Http\Controllers\Api\AiExtractionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankChangeRequestController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\RfqProposalController;
use App\Http\Controllers\Api\SupplierBankAccountController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('rfqs', RfqController::class)->except(['destroy']);
    Route::get('/rfqs/{rfq}/extractions', [AiExtractionController::class, 'index']);
    Route::post('/rfqs/{rfq}/extractions', [AiExtractionController::class, 'store']);
    Route::post('/proposals/{proposal}/approve', [RfqProposalController::class, 'approve']);
    Route::post('/proposals/{proposal}/reject', [RfqProposalController::class, 'reject']);

    Route::get('/suppliers/{supplier}/bank-account', [SupplierBankAccountController::class, 'show']);
    Route::post('/suppliers/{supplier}/bank-change-requests', [BankChangeRequestController::class, 'store']);
    Route::get('/bank-change-requests/{bankChangeRequest}', [BankChangeRequestController::class, 'show']);
    Route::post('/bank-change-requests/{bankChangeRequest}/approve', [BankChangeRequestController::class, 'approve']);
    Route::post('/bank-change-requests/{bankChangeRequest}/reject', [BankChangeRequestController::class, 'reject']);
});
