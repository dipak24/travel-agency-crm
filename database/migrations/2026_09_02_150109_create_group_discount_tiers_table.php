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
        Schema::create('group_discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('min_pax');
            $table->unsignedInteger('max_pax')->nullable();
            $table->string('discount_type');
            $table->unsignedInteger('discount_value');
            $table->timestamps();
            $table->index(['tenant_id', 'package_id', 'min_pax']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_discount_tiers');
    }
};
