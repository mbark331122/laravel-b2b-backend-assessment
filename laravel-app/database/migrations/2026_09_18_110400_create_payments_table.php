<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->string('method');
            $table->string('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('active_lock');
            $table->index(['buyer_company_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
