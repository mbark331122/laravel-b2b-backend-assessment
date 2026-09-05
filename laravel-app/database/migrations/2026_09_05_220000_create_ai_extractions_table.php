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
        Schema::create('ai_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->restrictOnDelete();
            $table->string('commodity');
            $table->string('specification');
            $table->unsignedInteger('quantity');
            $table->string('unit');
            $table->string('incoterm');
            $table->string('destination');
            $table->decimal('confidence', 5, 2);
            $table->string('source');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_extractions');
    }
};
