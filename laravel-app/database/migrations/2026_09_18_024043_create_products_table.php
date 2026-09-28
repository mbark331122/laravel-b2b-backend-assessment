<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->text('description')->nullable();
            $table->string('unit');
            $table->unsignedInteger('minimum_order_quantity');
            $table->decimal('wholesale_price', 12, 2);
            $table->string('currency', 3);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->index('company_id');
            $table->index('product_category_id');
            $table->index('status');
            $table->index(['status', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
