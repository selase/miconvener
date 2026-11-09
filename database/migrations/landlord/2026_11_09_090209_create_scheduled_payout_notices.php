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
        Schema::connection('landlord')->create('scheduled_payout_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('policy_id')->constrained('organisation_payout_policies')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->text('recipient_email')->nullable();
            $table->string('kind', 40);
            $table->string('dedupe_key', 200)->unique();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('scheduled_payout_notices');
    }
};
