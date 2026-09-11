<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_gateways');
    }
};
