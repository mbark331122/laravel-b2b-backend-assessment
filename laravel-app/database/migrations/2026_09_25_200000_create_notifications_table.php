<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 — in-app notifications (tenant + recipient scoped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            // Deterministic dedupe: one notification per recipient per domain event key.
            $table->string('dedupe_key');
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
            $table->index(['user_id', 'company_id', 'read_at', 'id'], 'notifications_inbox_index');
            $table->index(['company_id', 'type'], 'notifications_company_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
