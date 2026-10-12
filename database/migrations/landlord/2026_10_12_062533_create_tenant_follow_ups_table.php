<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A log of marketing following up with a new organisation: who did what,
     * when, and what came of it. The latest entry is the organisation's status.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_follow_ups');
    }
};
