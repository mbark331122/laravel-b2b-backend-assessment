<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 17 — indexes justified by existing query patterns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_parties', function (Blueprint $table) {
            // MultiPartyConfidentialityController::parties — where(po_id)->where(status)
            $table->index(['purchase_order_id', 'status'], 'po_parties_po_status_index');
        });

        Schema::table('products', function (Blueprint $table) {
            // SupplierMatchingService / Product::applyCatalogFilters brand + published
            $table->index(['status', 'brand_id'], 'products_status_brand_index');
            // Product catalog filter by supplier_profile_id
            $table->index(['supplier_profile_id'], 'products_supplier_profile_index');
        });

        Schema::table('ai_extractions', function (Blueprint $table) {
            // AiExtractionController::index — $rfq->aiExtractions()
            $table->index(['rfq_id'], 'ai_extractions_rfq_id_index');
        });

        Schema::table('negotiations', function (Blueprint $table) {
            // NegotiationController::indexForRfq — where(rfq_id)->where(buyer_company_id)
            $table->index(['rfq_id', 'buyer_company_id'], 'negotiations_rfq_buyer_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_parties', function (Blueprint $table) {
            $table->dropIndex('po_parties_po_status_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_status_brand_index');
            $table->dropIndex('products_supplier_profile_index');
        });

        Schema::table('ai_extractions', function (Blueprint $table) {
            $table->dropIndex('ai_extractions_rfq_id_index');
        });

        Schema::table('negotiations', function (Blueprint $table) {
            $table->dropIndex('negotiations_rfq_buyer_index');
        });
    }
};
