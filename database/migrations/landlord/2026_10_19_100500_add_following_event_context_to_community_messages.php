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
        Schema::connection('landlord')->table('community_followers', function (Blueprint $table): void {
            $table->timestampTz('consented_at', 6)->change();
        });
        Schema::connection('landlord')->table('community_messages', function (Blueprint $table): void {
            $table->timestampTz('started_at', 6)->nullable()->change();
            $table->string('purpose', 30)->default('community');
            $table->foreignUuid('source_event_id')->nullable()->constrained('events');
            $table->uuid('last_follower_id')->nullable();
            $table->index(['tenant_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('community_followers', function (Blueprint $table): void {
            $table->timestampTz('consented_at')->change();
        });
        Schema::connection('landlord')->table('community_messages', function (Blueprint $table): void {
            $table->timestampTz('started_at')->nullable()->change();
            $table->dropForeign(['source_event_id']);
            $table->dropIndex(['tenant_id', 'purpose']);
            $table->dropColumn(['purpose', 'source_event_id', 'last_follower_id']);
        });
    }
};
