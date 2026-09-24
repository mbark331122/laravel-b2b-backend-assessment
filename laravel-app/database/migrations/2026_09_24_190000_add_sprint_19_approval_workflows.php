<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 — approval policies, requests, decisions (tenant-scoped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('approval_type');
            $table->boolean('is_active')->default(true);
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'approval_type', 'is_active'], 'approval_policies_company_type_active_index');
        });

        Schema::create('approval_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('approver_role');
            $table->timestamps();

            $table->unique(['approval_policy_id', 'step_order'], 'approval_policy_steps_policy_order_unique');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approval_type');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('status');
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('current_step_order')->default(1);
            $table->string('policy_name_snapshot');
            $table->json('policy_steps_snapshot');
            $table->json('target_context_snapshot');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            // One pending request per target+type.
            $table->string('pending_lock')->nullable()->unique();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'approval_requests_company_status_index');
            $table->index(['target_type', 'target_id'], 'approval_requests_target_index');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('approver_role');
            $table->string('status');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_request_id', 'step_order'], 'approval_decisions_request_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_policy_steps');
        Schema::dropIfExists('approval_policies');
    }
};
