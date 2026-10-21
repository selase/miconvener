<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is emailed about platform events such as an Enterprise enquiry (set by a
 * superadmin, any address), and a lead's follow-up: its notes and when its
 * status last changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('platform_notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 64);
            $table->string('email');
            $table->timestamps();
            $table->unique(['topic', 'email']);
        });

        // The owner receives enquiries from the start (owner, 2026-10-19).
        DB::connection('landlord')->table('platform_notification_recipients')->insert([
            'topic' => 'enterprise_leads', 'email' => 'hiselase@gmail.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::connection('landlord')->table('leads', function (Blueprint $table): void {
            $table->text('notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('leads', fn (Blueprint $table) => $table->dropColumn(['notes', 'status_changed_at']));
        Schema::connection('landlord')->dropIfExists('platform_notification_recipients');
    }
};
