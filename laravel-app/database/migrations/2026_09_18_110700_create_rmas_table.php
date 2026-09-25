<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmas', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('requested');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('active_lock')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('active_lock');
            $table->index(['buyer_company_id', 'status']);
            $table->index(['supplier_company_id', 'status']);
            $table->index(['shipment_id', 'status']);
            $table->index(['purchase_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmas');
    }
};
