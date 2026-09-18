<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negotiations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_distribution_id')->constrained('rfq_distributions')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status')->default('open');
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('accepted_offer_id')->nullable();
            $table->foreignId('accepted_by_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            // Set to quotation_id while open; null when closed. Prevents duplicate active negotiations.
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamps();

            $table->unique('active_lock');
            $table->index(['rfq_id', 'status']);
            $table->index(['buyer_company_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['quotation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiations');
    }
};
