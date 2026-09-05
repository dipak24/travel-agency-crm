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
        Schema::create('tenant_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_no');
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'invoice_no']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_invoices');
    }
};
