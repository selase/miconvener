<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['members', 'community_followers', 'community_messages'] as $name) {
            Schema::connection('landlord')->table($name, function (Blueprint $table) use ($name): void {
                $table->unique(['tenant_id', 'id'], $name.'_inbox_tenant_id');
            });
        }
        Schema::connection('landlord')->table('community_messages', function (Blueprint $table): void {
            $table->timestampTz('inbox_withdrawn_at')->nullable();
        });
        Schema::connection('landlord')->create('community_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->uuid('message_id')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('recipient_type', 12);
            $table->uuid('member_id')->nullable();
            $table->uuid('follower_id')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('members')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'follower_id'])->references(['tenant_id', 'id'])->on('community_followers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'message_id'])->references(['tenant_id', 'id'])->on('community_messages')->cascadeOnDelete();
            $table->unique(['message_id', 'member_id']);
            $table->unique(['message_id', 'follower_id']);
            $table->unique(['kind', 'source_type', 'source_id', 'member_id'], 'inbox_notice_source_unique');
            $table->index(['member_id', 'available_at', 'id']);
            $table->index(['follower_id', 'available_at', 'id']);
            $table->index('message_id');
        });
        $connection = Schema::connection('landlord')->getConnection();
        $connection->statement("ALTER TABLE community_inbox_items ADD CONSTRAINT inbox_recipient_check CHECK ((recipient_type='member' AND member_id IS NOT NULL AND follower_id IS NULL) OR (recipient_type='follower' AND follower_id IS NOT NULL AND member_id IS NULL))");
        $connection->statement("ALTER TABLE community_inbox_items ADD CONSTRAINT inbox_content_check CHECK ((kind='announcement' AND message_id IS NOT NULL AND source_type IS NULL AND source_id IS NULL AND subject IS NULL AND body IS NULL) OR (kind IN ('dues_reminder','dues_receipt') AND recipient_type='member' AND message_id IS NULL AND source_type IS NOT NULL AND source_id IS NOT NULL AND subject IS NOT NULL AND body IS NOT NULL))");
        Schema::connection('landlord')->create('community_inbox_reads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('community_inbox_items')->cascadeOnDelete();
            $table->uuid('entry_key');
            $table->char('reader_key', 64);
            $table->timestampTz('read_at');
            $table->unique(['entry_key', 'reader_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_inbox_reads');
        Schema::connection('landlord')->dropIfExists('community_inbox_items');
        Schema::connection('landlord')->table('community_messages', fn (Blueprint $table) => $table->dropColumn('inbox_withdrawn_at'));
        foreach (['members', 'community_followers', 'community_messages'] as $name) {
            Schema::connection('landlord')->table($name, fn (Blueprint $table) => $table->dropUnique($name.'_inbox_tenant_id'));
        }
    }
};
