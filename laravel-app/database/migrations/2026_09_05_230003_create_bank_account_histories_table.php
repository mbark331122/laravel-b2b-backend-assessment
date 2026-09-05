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
        Schema::create('bank_account_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bank_account_id')->constrained()->restrictOnDelete();
            $table->string('beneficiary_name');
            $table->string('bank_name');
            $table->string('iban');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_account_histories');
    }
};
