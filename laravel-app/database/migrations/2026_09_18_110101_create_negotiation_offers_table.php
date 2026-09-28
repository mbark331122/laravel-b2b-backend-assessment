<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negotiation_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negotiation_id')->constrained('negotiations')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('created_by_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('side'); // buyer | supplier
            $table->string('status')->default('proposed'); // proposed | superseded | accepted
            $table->string('currency', 3);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('shipping_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['negotiation_id', 'sequence']);
            $table->index(['negotiation_id', 'status']);
        });

        Schema::table('negotiations', function (Blueprint $table) {
            $table->foreign('accepted_offer_id')
                ->references('id')
                ->on('negotiation_offers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('negotiations', function (Blueprint $table) {
            $table->dropForeign(['accepted_offer_id']);
        });
        Schema::dropIfExists('negotiation_offers');
    }
};
