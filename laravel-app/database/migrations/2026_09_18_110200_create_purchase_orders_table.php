<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('negotiation_id')->unique()->constrained('negotiations')->restrictOnDelete();
            $table->foreignId('accepted_offer_id')->constrained('negotiation_offers')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('rfq_distribution_id')->constrained('rfq_distributions')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('currency', 3);
            $table->decimal('shipping_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_company_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['rfq_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
