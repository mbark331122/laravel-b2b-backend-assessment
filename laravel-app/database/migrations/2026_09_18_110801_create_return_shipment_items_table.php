<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_shipment_id')->constrained('return_shipments')->cascadeOnDelete();
            $table->foreignId('rma_item_id')->constrained('rma_items')->restrictOnDelete();
            $table->foreignId('shipment_item_id')->constrained('shipment_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('unit');
            $table->string('description');
            $table->decimal('unit_price_snapshot', 14, 2)->default(0);
            $table->decimal('line_total_snapshot', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->json('product_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['return_shipment_id', 'rma_item_id']);
            $table->index(['return_shipment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_shipment_items');
    }
};
