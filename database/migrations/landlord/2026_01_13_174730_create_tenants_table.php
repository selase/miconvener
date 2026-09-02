<?php

declare(strict_types=1);

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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default('active'); // active/suspended
            $table->string('isolation_mode')->default('shared'); // shared | db_per_tenant | byo
            $table->string('db_driver')->default('mysql'); // mysql | pgsql
            $table->string('db_secret_ref')->nullable();
            $table->string('s3_mode')->default('shared'); // shared | byo
            $table->string('s3_secret_ref')->nullable();
            $table->boolean('encryption_at_rest')->default(false);
            $table->string('kms_key_ref')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
