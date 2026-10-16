<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('platform_phone_access_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('phone');
            $table->string('phone_hash', 64)->index();
            $table->string('ip_hash', 64)->index();
            $table->string('code_hash')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('landlord')->create('platform_phone_send_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('day')->index();
            $table->string('status', 20)->default('reserved');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('platform_phone_access_codes');
        Schema::connection('landlord')->dropIfExists('platform_phone_send_reservations');
    }
};
