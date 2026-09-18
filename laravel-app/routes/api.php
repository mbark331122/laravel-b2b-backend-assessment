<?php

use App\Http\Controllers\Api\AiExtractionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankChangeRequestController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BuyerQuotationController;
use App\Http\Controllers\Api\NegotiationController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductPriceTierController;
use App\Http\Controllers\Api\ProductSpecificationController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\RfqDistributionController;
use App\Http\Controllers\Api\RfqItemController;
use App\Http\Controllers\Api\RfqProposalController;
use App\Http\Controllers\Api\SupplierBankAccountController;
use App\Http\Controllers\Api\SupplierProfileController;
use App\Http\Controllers\Api\SupplierQuotationController;
use App\Http\Controllers\Api\SupplierRfqController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('rfqs', RfqController::class);
    Route::post('/rfqs/{rfq}/submit', [RfqController::class, 'submit']);
    Route::post('/rfqs/{rfq}/cancel', [RfqController::class, 'cancel']);
    Route::post('/rfqs/{rfq}/close', [RfqController::class, 'close']);
    Route::get('/rfqs/{rfq}/suppliers', [RfqDistributionController::class, 'match']);
    Route::get('/rfqs/{rfq}/distributions', [RfqDistributionController::class, 'index']);
    Route::post('/rfqs/{rfq}/distributions', [RfqDistributionController::class, 'store']);
    Route::post('/rfqs/{rfq}/distributions/{distribution}/withdraw', [RfqDistributionController::class, 'withdraw']);
    Route::get('/rfqs/{rfq}/quotations/compare', [BuyerQuotationController::class, 'compare']);
    Route::get('/rfqs/{rfq}/quotations', [BuyerQuotationController::class, 'index']);
    Route::get('/rfqs/{rfq}/quotations/{quotation}', [BuyerQuotationController::class, 'show']);
    Route::get('/rfqs/{rfq}/negotiations', [NegotiationController::class, 'indexForRfq']);
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

    Route::get('/supplier/rfqs', [SupplierRfqController::class, 'index']);
    Route::get('/supplier/rfqs/{rfq}', [SupplierRfqController::class, 'show']);
    Route::post('/supplier/rfq-distributions/{distribution}/quotation', [SupplierQuotationController::class, 'store']);
    Route::get('/supplier/quotations/{quotation}', [SupplierQuotationController::class, 'show']);
    Route::put('/supplier/quotations/{quotation}', [SupplierQuotationController::class, 'update']);
    Route::patch('/supplier/quotations/{quotation}', [SupplierQuotationController::class, 'update']);
    Route::delete('/supplier/quotations/{quotation}', [SupplierQuotationController::class, 'destroy']);
    Route::post('/supplier/quotations/{quotation}/submit', [SupplierQuotationController::class, 'submit']);
    Route::post('/supplier/quotations/{quotation}/withdraw', [SupplierQuotationController::class, 'withdraw']);
    Route::post('/supplier/quotations/{quotation}/items', [SupplierQuotationController::class, 'storeItem']);
    Route::put('/supplier/quotations/{quotation}/items/{item}', [SupplierQuotationController::class, 'updateItem']);
    Route::patch('/supplier/quotations/{quotation}/items/{item}', [SupplierQuotationController::class, 'updateItem']);
    Route::delete('/supplier/quotations/{quotation}/items/{item}', [SupplierQuotationController::class, 'destroyItem']);

    Route::post('/quotations/{quotation}/negotiation', [NegotiationController::class, 'storeForQuotation']);
    Route::get('/negotiations/{negotiation}', [NegotiationController::class, 'show']);
    Route::get('/negotiations/{negotiation}/offers', [NegotiationController::class, 'offers']);
    Route::post('/negotiations/{negotiation}/offers', [NegotiationController::class, 'storeOffer']);
    Route::post('/negotiations/{negotiation}/offers/{offer}/accept', [NegotiationController::class, 'acceptOffer']);
    Route::post('/negotiations/{negotiation}/reject', [NegotiationController::class, 'reject']);
    Route::post('/negotiations/{negotiation}/withdraw', [NegotiationController::class, 'withdraw']);

    Route::get('/supplier/negotiations', [NegotiationController::class, 'indexForSupplier']);
    Route::get('/supplier/negotiations/{negotiation}', [NegotiationController::class, 'show']);
    Route::get('/supplier/negotiations/{negotiation}/offers', [NegotiationController::class, 'offers']);

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
