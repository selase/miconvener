<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_blasts', function (Blueprint $table): void {
            $table->string('audience_label')->nullable()->after('audience');
            $table->string('status')->default('sent')->after('recipients_count'); // scheduled, sent, cancelled
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('sent_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_blasts', function (Blueprint $table): void {
            $table->dropColumn(['audience_label', 'status', 'scheduled_at', 'sent_at']);
        });
    }
};
