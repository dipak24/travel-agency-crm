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
        Schema::create('tenant_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('method');
            $table->string('type')->default('installment');
            $table->string('status')->default('pending');
            $table->string('transaction_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'tenant_invoice_id', 'status']);
            $table->unique(['tenant_id', 'transaction_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_payments');
    }
};
