<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rfq_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_extraction_id')->constrained()->restrictOnDelete();
            $table->string('field');
            $table->string('current_value');
            $table->string('proposed_value');
            $table->string('source');
            $table->decimal('confidence', 5, 2);
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfq_proposals');
    }
};
