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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('package_code');
            $table->text('description')->nullable();
            $table->json('itinerary')->nullable();
            $table->unsignedInteger('base_price')->default(0);
            $table->unsignedInteger('sales_price')->default(0);
            $table->unsignedSmallInteger('duration_days');
            $table->boolean('is_public')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'package_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
