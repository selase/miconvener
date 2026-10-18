<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('event_sessions', fn (Blueprint $table) => $table->unique(['tenant_id', 'event_id', 'id'], 'library_session_context_unique'));
        Schema::connection('landlord')->create('community_library_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->default('document');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('plain_text')->nullable();
            $table->jsonb('audience');
            $table->string('state', 16)->default('draft');
            $table->timestampTz('release_at')->nullable();
            $table->uuid('current_version_id')->nullable();
            $table->uuid('event_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'library_item_tenant_unique');
            $table->foreign(['tenant_id', 'event_id'])->references(['tenant_id', 'id'])->on('events')->restrictOnDelete();
            $table->foreign(['tenant_id', 'event_id', 'session_id'])->references(['tenant_id', 'event_id', 'id'])->on('event_sessions')->restrictOnDelete();
            $table->index(['tenant_id', 'state', 'release_at']);
            $table->index(['tenant_id', 'session_id']);
        });
        DB::connection('landlord')->statement("ALTER TABLE community_library_items ADD CONSTRAINT library_item_context CHECK ((kind = 'document' AND event_id IS NULL AND session_id IS NULL) OR (kind = 'service_note' AND event_id IS NOT NULL AND session_id IS NOT NULL)), ADD CONSTRAINT library_item_state CHECK (state IN ('draft', 'published', 'withdrawn'))");
        Schema::connection('landlord')->create('community_library_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('item_id');
            $table->string('edition', 120);
            $table->string('disk', 64);
            $table->string('path');
            $table->string('mime', 160);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('community_library_items')->cascadeOnDelete();
            $table->unique(['tenant_id', 'item_id', 'id'], 'library_version_context_unique');
            $table->unique(['disk', 'path']);
        });
        Schema::connection('landlord')->table('community_library_items', fn (Blueprint $table) => $table->foreign(['tenant_id', 'id', 'current_version_id'], 'library_current_version_context')->references(['tenant_id', 'item_id', 'id'])->on('community_library_versions')->restrictOnDelete());
        foreach (['community_library_access' => ['Community Library', ['starter', 'growth', 'enterprise']], 'community_polls_access' => ['Community Polls and Surveys', ['growth', 'enterprise']]] as $slug => [$name, $allowed]) {
            $feature = App\Models\Feature::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'type' => 'boolean', 'description' => $name]);
            foreach (App\Models\Package::query()->whereIn('slug', ['free', 'starter', 'growth', 'enterprise'])->get() as $package) {
                if (! $package->features()->whereKey($feature->id)->exists()) {
                    $package->features()->attach($feature->id, ['value' => in_array($package->slug, $allowed, true)]);
                }
            }
        }

    }

    public function down(): void
    {
        Schema::connection('landlord')->table('community_library_items', fn (Blueprint $table) => $table->dropForeign('library_current_version_context'));
        Schema::connection('landlord')->dropIfExists('community_library_versions');
        Schema::connection('landlord')->dropIfExists('community_library_items');
        Schema::connection('landlord')->table('event_sessions', fn (Blueprint $table) => $table->dropUnique('library_session_context_unique'));
    }
};
