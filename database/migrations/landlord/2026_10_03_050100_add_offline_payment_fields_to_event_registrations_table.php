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
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->string('payment_method', 32)->nullable()->after('payment_reference');
            $table->string('offline_payment_status', 32)->nullable()->after('payment_method');
            $table->string('offline_payment_proof_path')->nullable()->after('offline_payment_status');
            $table->string('offline_payment_reference')->nullable()->after('offline_payment_proof_path');
            $table->text('offline_payment_notes')->nullable()->after('offline_payment_reference');
            $table->dateTime('offline_payment_submitted_at')->nullable()->after('offline_payment_notes');
            $table->dateTime('offline_payment_verified_at')->nullable()->after('offline_payment_submitted_at');
            $table->foreignId('offline_payment_verified_by')->nullable()->after('offline_payment_verified_at')->constrained('users')->nullOnDelete();

            $table->index(['event_id', 'offline_payment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropIndex(['event_id', 'offline_payment_status']);
            $table->dropForeign(['offline_payment_verified_by']);
            $table->dropColumn([
                'payment_method',
                'offline_payment_status',
                'offline_payment_proof_path',
                'offline_payment_reference',
                'offline_payment_notes',
                'offline_payment_submitted_at',
                'offline_payment_verified_at',
                'offline_payment_verified_by',
            ]);
        });
    }
};
