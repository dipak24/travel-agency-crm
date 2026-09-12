<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_settings');
    }
};
