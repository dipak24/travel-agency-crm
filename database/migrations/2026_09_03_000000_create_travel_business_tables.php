<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            $table->string('origin')->default('manual');
            $table->string('destination')->nullable();
            $table->string('trip_type')->nullable();
            $table->unsignedInteger('pax_count')->default(1);
            $table->string('budget_range')->nullable();
            $table->string('status')->default('new');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->date('follow_up_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'follow_up_date']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fixed_departure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trip_name');
            $table->json('booked_itinerary')->nullable();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('pax_count')->default(1);
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'start_date']);
        });

        Schema::create('booking_travelers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('passport_no')->nullable();
            $table->date('dob')->nullable();
            $table->string('document_status')->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id']);
        });

        Schema::create('booking_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('uploaded_by');
            $table->string('file_path');
            $table->string('doc_type');
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by_staff_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id', 'status']);
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->boolean('has_limited_availability')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('service_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('total_slots');
            $table->unsignedInteger('booked_slots')->default(0);
            $table->timestamps();
            $table->unique(['service_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('booking_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('requested');
            $table->string('added_by');
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id']);
        });

        Schema::create('booking_include_exclude', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('include_exclude_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id', 'type']);
        });

        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('discount_type');
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('gift_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->unsignedBigInteger('value')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('issued_to')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status')->default('unredeemed');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('booking_waitlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_departure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('pax_requested')->default(1);
            $table->unsignedInteger('position');
            $table->string('status')->default('waiting');
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('notified_by_staff_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fixed_departure_id', 'position']);
            $table->index(['tenant_id', 'fixed_departure_id', 'status']);
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_type');
            $table->string('recipient_type');
            $table->string('channel')->default('email');
            $table->string('reminder_rule');
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('queued');
            $table->timestamps();
            $table->index(['tenant_id', 'booking_id', 'requirement_type']);
        });

        Schema::create('public_lead_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('slug')->unique();
            $table->json('theme')->nullable();
            $table->json('contact_settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no');
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('draft');
            $table->date('due_date')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'invoice_no']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('method');
            $table->string('type')->default('installment');
            $table->string('status')->default('pending');
            $table->string('transaction_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id', 'status']);
            $table->unique(['tenant_id', 'transaction_ref']);
        });

        Schema::create('email_template_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_subject');
            $table->text('default_body_html');
            $table->json('available_merge_tags')->nullable();
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_template_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->string('name');
            $table->string('subject');
            $table->text('body_html');
            $table->string('status')->default('draft');
            $table->foreignId('updated_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'email_template_type_id']);
            $table->index(['tenant_id', 'category', 'status']);
        });

        Schema::create('platform_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('category');
            $table->string('name');
            $table->string('subject');
            $table->text('body_html');
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('saas_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('company')->nullable();
            $table->string('status')->default('new');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('name');
            $table->string('subject_override')->nullable();
            $table->string('audience_type');
            $table->json('audience_filter')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'tenant_id', 'status']);
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('email');
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('email_unsubscribes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('unsubscribed_at');
            $table->string('source')->default('manual');
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('email_unsubscribes');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('saas_leads');
        Schema::dropIfExists('platform_email_templates');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_template_types');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('public_lead_pages');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('booking_waitlist');
        Schema::dropIfExists('booking_include_exclude');
        Schema::dropIfExists('booking_addons');
        Schema::dropIfExists('service_availability');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_documents');
        Schema::dropIfExists('booking_travelers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('leads');
    }
};
