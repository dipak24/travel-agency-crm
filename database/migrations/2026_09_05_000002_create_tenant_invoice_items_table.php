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
        Schema::create('tenant_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('other');
            $table->string('description');
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'tenant_invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_invoice_items');
    }
};
