<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 18 — company business profile, addresses, contacts, invitations, member active flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role_id');
        });

        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name')->nullable();
            $table->text('business_description')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('website')->nullable();
            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->timestamps();
        });

        Schema::create('company_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('label')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address_line');
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'type'], 'company_addresses_company_type_index');
        });

        Schema::create('company_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_type')->default('general');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'company_contacts_company_active_index');
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('token_hash');
            $table->string('status');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Set only while status=pending; prevents duplicate active invites for same company+email.
            $table->string('pending_lock')->nullable()->unique();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index(['company_id', 'status'], 'company_invitations_company_status_index');
            $table->index(['company_id', 'email'], 'company_invitations_company_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('company_addresses');
        Schema::dropIfExists('company_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
