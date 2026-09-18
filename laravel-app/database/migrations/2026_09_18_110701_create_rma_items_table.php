<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rma_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rma_id')->constrained('rmas')->cascadeOnDelete();
            $table->foreignId('shipment_item_id')->constrained('shipment_items')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->string('unit');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rma_id', 'shipment_item_id']);
            $table->index(['rma_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rma_items');
    }
};
