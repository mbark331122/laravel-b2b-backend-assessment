<?php

use App\Http\Controllers\Api\AiExtractionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankChangeRequestController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BuyerQuotationController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\DeliveryConfirmationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\NegotiationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductPriceTierController;
use App\Http\Controllers\Api\ProductSpecificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReturnShipmentController;
use App\Http\Controllers\Api\RfqController;
use App\Http\Controllers\Api\RfqDistributionController;
use App\Http\Controllers\Api\RfqItemController;
use App\Http\Controllers\Api\RfqProposalController;
use App\Http\Controllers\Api\RmaController;
use App\Http\Controllers\Api\ShipmentController;
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
    Route::post('/negotiations/{negotiation}/purchase-order', [PurchaseOrderController::class, 'store']);

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::post('/purchase-orders/{purchaseOrder}/complete', [PurchaseOrderController::class, 'complete']);
    Route::post('/purchase-orders/{purchaseOrder}/invoice', [InvoiceController::class, 'store']);
    Route::get('/purchase-orders/{purchaseOrder}/invoice', [InvoiceController::class, 'showForPurchaseOrder']);
    Route::post('/purchase-orders/{purchaseOrder}/shipment', [ShipmentController::class, 'store']);
    Route::get('/purchase-orders/{purchaseOrder}/shipment', [ShipmentController::class, 'showForPurchaseOrder']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void']);
    Route::post('/invoices/{invoice}/payment', [PaymentController::class, 'store']);

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/payments/{payment}/mark-paid', [PaymentController::class, 'markPaid']);
    Route::post('/payments/{payment}/mark-failed', [PaymentController::class, 'markFailed']);
    Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancel']);

    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);
    Route::patch('/shipments/{shipment}', [ShipmentController::class, 'update']);
    Route::post('/shipments/{shipment}/processing', [ShipmentController::class, 'processing']);
    Route::post('/shipments/{shipment}/ship', [ShipmentController::class, 'ship']);
    Route::post('/shipments/{shipment}/deliver', [ShipmentController::class, 'deliver']);
    Route::post('/shipments/{shipment}/cancel', [ShipmentController::class, 'cancel']);
    Route::post('/shipments/{shipment}/delivery-confirmation', [DeliveryConfirmationController::class, 'store']);
    Route::get('/shipments/{shipment}/delivery-confirmation', [DeliveryConfirmationController::class, 'showForShipment']);

    Route::get('/delivery-confirmations', [DeliveryConfirmationController::class, 'index']);
    Route::get('/delivery-confirmations/{deliveryConfirmation}', [DeliveryConfirmationController::class, 'show']);

    Route::post('/shipments/{shipment}/rma', [RmaController::class, 'store']);
    Route::get('/rmas', [RmaController::class, 'index']);
    Route::get('/rmas/{rma}', [RmaController::class, 'show']);
    Route::post('/rmas/{rma}/cancel', [RmaController::class, 'cancel']);
    Route::post('/rmas/{rma}/return-shipment', [ReturnShipmentController::class, 'store']);
    Route::get('/rmas/{rma}/return-shipment', [ReturnShipmentController::class, 'showForRma']);
    Route::post('/rmas/{rma}/credit-note', [CreditNoteController::class, 'store']);
    Route::get('/rmas/{rma}/credit-note', [CreditNoteController::class, 'showForRma']);

    Route::get('/return-shipments', [ReturnShipmentController::class, 'index']);
    Route::get('/return-shipments/{returnShipment}', [ReturnShipmentController::class, 'show']);
    Route::patch('/return-shipments/{returnShipment}', [ReturnShipmentController::class, 'update']);
    Route::post('/return-shipments/{returnShipment}/ship', [ReturnShipmentController::class, 'ship']);
    Route::post('/return-shipments/{returnShipment}/deliver', [ReturnShipmentController::class, 'deliver']);
    Route::post('/return-shipments/{returnShipment}/cancel', [ReturnShipmentController::class, 'cancel']);

    Route::get('/credit-notes', [CreditNoteController::class, 'index']);
    Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show']);
    Route::post('/credit-notes/{creditNote}/issue', [CreditNoteController::class, 'issue']);
    Route::post('/credit-notes/{creditNote}/cancel', [CreditNoteController::class, 'cancel']);
    Route::post('/credit-notes/{creditNote}/void', [CreditNoteController::class, 'void']);
    Route::post('/credit-notes/{creditNote}/refund', [RefundController::class, 'store']);

    Route::get('/refunds', [RefundController::class, 'index']);
    Route::get('/refunds/{refund}', [RefundController::class, 'show']);
    Route::post('/refunds/{refund}/process', [RefundController::class, 'process']);
    Route::post('/refunds/{refund}/fail', [RefundController::class, 'fail']);
    Route::post('/refunds/{refund}/cancel', [RefundController::class, 'cancel']);

    Route::get('/supplier/negotiations', [NegotiationController::class, 'indexForSupplier']);
    Route::get('/supplier/negotiations/{negotiation}', [NegotiationController::class, 'show']);
    Route::get('/supplier/negotiations/{negotiation}/offers', [NegotiationController::class, 'offers']);

    Route::get('/supplier/purchase-orders', [PurchaseOrderController::class, 'indexForSupplier']);
    Route::get('/supplier/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::post('/supplier/purchase-orders/{purchaseOrder}/confirm', [PurchaseOrderController::class, 'confirm']);
    Route::post('/supplier/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);

    Route::get('/supplier/invoices', [InvoiceController::class, 'indexForSupplier']);
    Route::get('/supplier/invoices/{invoice}', [InvoiceController::class, 'show']);

    Route::get('/supplier/payments', [PaymentController::class, 'indexForSupplier']);
    Route::get('/supplier/payments/{payment}', [PaymentController::class, 'show']);

    Route::get('/supplier/shipments', [ShipmentController::class, 'indexForSupplier']);
    Route::get('/supplier/shipments/{shipment}', [ShipmentController::class, 'show']);

    Route::get('/supplier/delivery-confirmations', [DeliveryConfirmationController::class, 'indexForSupplier']);
    Route::get('/supplier/delivery-confirmations/{deliveryConfirmation}', [DeliveryConfirmationController::class, 'show']);

    Route::get('/supplier/rmas', [RmaController::class, 'indexForSupplier']);
    Route::get('/supplier/rmas/{rma}', [RmaController::class, 'show']);
    Route::post('/supplier/rmas/{rma}/approve', [RmaController::class, 'approve']);
    Route::post('/supplier/rmas/{rma}/reject', [RmaController::class, 'reject']);
    Route::post('/supplier/rmas/{rma}/received', [RmaController::class, 'received']);
    Route::post('/supplier/rmas/{rma}/close', [RmaController::class, 'close']);

    Route::get('/supplier/return-shipments', [ReturnShipmentController::class, 'indexForSupplier']);
    Route::get('/supplier/return-shipments/{returnShipment}', [ReturnShipmentController::class, 'show']);

    Route::get('/supplier/credit-notes', [CreditNoteController::class, 'indexForSupplier']);
    Route::get('/supplier/credit-notes/{creditNote}', [CreditNoteController::class, 'show']);

    Route::get('/supplier/refunds', [RefundController::class, 'indexForSupplier']);
    Route::get('/supplier/refunds/{refund}', [RefundController::class, 'show']);

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
