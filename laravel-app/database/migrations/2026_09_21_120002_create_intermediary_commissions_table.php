<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intermediary_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_order_party_id')->unique()->constrained('purchase_order_parties')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intermediary_commissions');
    }
};
