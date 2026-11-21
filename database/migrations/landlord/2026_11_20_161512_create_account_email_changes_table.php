<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('account_email_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('current_email');
            $table->string('new_email');
            $table->unsignedBigInteger('security_version');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('notification_status', 20)->default('pending');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'consumed_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('account_email_changes');
    }
};
