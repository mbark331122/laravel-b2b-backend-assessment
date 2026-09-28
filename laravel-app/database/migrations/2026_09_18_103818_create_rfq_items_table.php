<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('product_sku')->nullable();
            $table->string('product_brand_name')->nullable();
            $table->string('unit');
            $table->unsignedInteger('quantity');
            $table->decimal('target_price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('requirements')->nullable();
            $table->json('product_snapshot')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('rfq_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_items');
    }
};
