<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_distribution_id')->constrained('rfq_distributions')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('currency', 3);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('shipping_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            // Set to rfq_distribution_id while draft/submitted; null when withdrawn/expired.
            // Enforces at most one active quotation per distribution (NULLs are not unique-colliding).
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamps();

            $table->unique('active_lock');
            $table->index(['rfq_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['rfq_distribution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
