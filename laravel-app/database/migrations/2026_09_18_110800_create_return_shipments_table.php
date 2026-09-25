<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('rma_id')->constrained('rmas')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('shipping_method')->nullable();
            $table->json('origin_address_snapshot')->nullable();
            $table->json('destination_address_snapshot')->nullable();
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('active_lock');
            $table->index(['buyer_company_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['rma_id', 'status']);
            $table->index(['shipment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_shipments');
    }
};
