<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negotiation_offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negotiation_offer_id')->constrained('negotiation_offers')->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained('rfq_items')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->string('unit');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->text('notes')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['negotiation_offer_id', 'rfq_item_id']);
            $table->index(['negotiation_offer_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiation_offer_items');
    }
};
