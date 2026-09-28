<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_identity_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('viewer_party_id')->constrained('purchase_order_parties')->restrictOnDelete();
            $table->foreignId('visible_party_id')->constrained('purchase_order_parties')->restrictOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->unique(['viewer_party_id', 'visible_party_id']);
            $table->index(['purchase_order_id', 'viewer_party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_identity_grants');
    }
};
