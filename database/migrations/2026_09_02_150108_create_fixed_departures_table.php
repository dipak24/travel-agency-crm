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
        Schema::create('fixed_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('total_slots');
            $table->unsignedInteger('overbooking_buffer')->default(0);
            $table->unsignedInteger('booked_slots')->default(0);
            $table->unsignedInteger('price_override')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'start_date']);
            $table->index(['package_id', 'status', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_departures');
    }
};
