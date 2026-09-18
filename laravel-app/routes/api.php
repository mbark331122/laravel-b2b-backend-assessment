<?php

use App\Http\Controllers\Api\AiExtractionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankChangeRequestController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductPriceTierController;
use App\Http\Controllers\Api\ProductSpecificationController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\RfqItemController;
use App\Http\Controllers\Api\RfqProposalController;
use App\Http\Controllers\Api\SupplierBankAccountController;
use App\Http\Controllers\Api\SupplierProfileController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('rfqs', RfqController::class);
    Route::post('/rfqs/{rfq}/submit', [RfqController::class, 'submit']);
    Route::post('/rfqs/{rfq}/cancel', [RfqController::class, 'cancel']);
    Route::post('/rfqs/{rfq}/close', [RfqController::class, 'close']);
    Route::post('/rfqs/{rfq}/items', [RfqItemController::class, 'store']);
    Route::put('/rfqs/{rfq}/items/{item}', [RfqItemController::class, 'update']);
    Route::patch('/rfqs/{rfq}/items/{item}', [RfqItemController::class, 'update']);
    Route::delete('/rfqs/{rfq}/items/{item}', [RfqItemController::class, 'destroy']);
    Route::get('/rfqs/{rfq}/extractions', [AiExtractionController::class, 'index']);
    Route::post('/rfqs/{rfq}/extractions', [AiExtractionController::class, 'store']);
    Route::post('/proposals/{proposal}/approve', [RfqProposalController::class, 'approve']);
    Route::post('/proposals/{proposal}/reject', [RfqProposalController::class, 'reject']);

    Route::get('/suppliers/{supplier}/bank-account', [SupplierBankAccountController::class, 'show']);
    Route::post('/suppliers/{supplier}/bank-change-requests', [BankChangeRequestController::class, 'store']);
    Route::get('/bank-change-requests/{bankChangeRequest}', [BankChangeRequestController::class, 'show']);
    Route::post('/bank-change-requests/{bankChangeRequest}/approve', [BankChangeRequestController::class, 'approve']);
    Route::post('/bank-change-requests/{bankChangeRequest}/reject', [BankChangeRequestController::class, 'reject']);

    Route::get('/supplier-profiles', [SupplierProfileController::class, 'index']);
    Route::get('/supplier-profile', [SupplierProfileController::class, 'show']);
    Route::post('/supplier-profile', [SupplierProfileController::class, 'store']);
    Route::put('/supplier-profiles/{supplierProfile}', [SupplierProfileController::class, 'update']);
    Route::patch('/supplier-profiles/{supplierProfile}', [SupplierProfileController::class, 'update']);
    Route::get('/supplier-profiles/{supplierProfile}', [SupplierProfileController::class, 'showById']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::post('/brands', [BrandController::class, 'store']);

    Route::apiResource('products', ProductController::class);
    Route::post('/products/{product}/transitions', [ProductController::class, 'transition']);
    Route::get('/products/{product}/specifications', [ProductSpecificationController::class, 'index']);
    Route::put('/products/{product}/specifications', [ProductSpecificationController::class, 'sync']);
    Route::get('/products/{product}/price-tiers', [ProductPriceTierController::class, 'index']);
    Route::put('/products/{product}/price-tiers', [ProductPriceTierController::class, 'sync']);
});
