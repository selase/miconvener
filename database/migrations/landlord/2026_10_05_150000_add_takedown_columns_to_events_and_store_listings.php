<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A superadmin can take down an abusive event or marketplace listing. The
 * item is hidden from the public, the owner cannot republish it, and the
 * reason is kept; restoring puts back the status it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->timestamp('taken_down_at')->nullable();
            $table->text('takedown_reason')->nullable();
            $table->unsignedBigInteger('taken_down_by')->nullable();
            $table->string('status_before_takedown', 20)->nullable();
        });

        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->timestamp('taken_down_at')->nullable();
            $table->text('takedown_reason')->nullable();
            $table->unsignedBigInteger('taken_down_by')->nullable();
            $table->string('status_before_takedown', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn(['taken_down_at', 'takedown_reason', 'taken_down_by', 'status_before_takedown']);
        });

        Schema::connection('landlord')->table('store_listings', function (Blueprint $table): void {
            $table->dropColumn(['taken_down_at', 'takedown_reason', 'taken_down_by', 'status_before_takedown']);
        });
    }
};
