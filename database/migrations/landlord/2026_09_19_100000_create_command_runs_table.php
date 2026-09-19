<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every operational command a Superadmin triggers from the console, and what
 * it did. These actions reach real money and real inboxes, so who ran what,
 * when, and what came back is worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('command_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('command_key');
            $table->string('command');
            $table->json('options')->nullable();
            $table->string('status')->default('queued');
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->string('triggered_by_email')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['command_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('command_runs');
    }
};
