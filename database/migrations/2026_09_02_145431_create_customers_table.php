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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('pending_email')->nullable();
            $table->timestamp('pending_email_requested_at')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_login_at')->nullable();
            $table->string('avatar')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->boolean('whatsapp_same_as_mobile')->default(false);
            $table->string('type')->default('individual');
            $table->text('address')->nullable();
            $table->text('passport_no')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->foreignId('nationality_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('country_of_residence_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('preferred_language')->nullable();
            $table->string('preferred_contact_method')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship')->nullable();
            $table->jsonb('travel_interests')->nullable();
            $table->string('preferred_activity')->nullable();
            $table->text('dietary_preferences')->nullable();
            $table->text('special_requirements')->nullable();
            $table->text('notes')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('customer_special_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label')->nullable();
            $table->date('date');
            $table->boolean('remind')->default(false);
            $table->unsignedSmallInteger('remind_days_before')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_special_dates');
        Schema::dropIfExists('customers');
    }
};
