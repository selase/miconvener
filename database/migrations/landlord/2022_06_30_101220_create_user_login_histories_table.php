<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_login_histories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('uuid');
            $table->integer('user_id');
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->string('client_device')->nullable();
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->dateTime('login_at')->nullable();
            $table->dateTime('logout_at')->nullable();
            $table->string('session_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_login_histories');
    }
};
