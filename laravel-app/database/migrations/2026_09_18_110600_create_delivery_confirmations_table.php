<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['buyer_company_id', 'confirmed_at']);
            $table->index(['supplier_company_id', 'confirmed_at']);
            $table->index(['purchase_order_id', 'confirmed_at']);
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_confirmations');
    }
};
