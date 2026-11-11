<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('badge_library_designs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('scope');
            $table->string('name', 120);
            $table->unsignedInteger('price_pesewas')->default(0);
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'scope']);
        });
        Schema::connection('landlord')->create('badge_library_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('design_id')->constrained('badge_library_designs')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->timestamps();
            $table->unique(['design_id', 'version']);
        });
        Schema::connection('landlord')->create('badge_design_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedInteger('design_version');
            $table->jsonb('snapshot');
            $table->timestamps();
        });
        Schema::connection('landlord')->table('event_badge_templates', function (Blueprint $table): void {
            $table->jsonb('back')->nullable();
            $table->uuid('source_version_id')->nullable();
        });
        DB::connection('landlord')->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION badge_library_immutable() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'Published badge versions are immutable'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER badge_library_versions_immutable BEFORE UPDATE ON badge_library_versions FOR EACH ROW EXECUTE FUNCTION badge_library_immutable();
            CREATE TRIGGER badge_design_revisions_immutable BEFORE UPDATE ON badge_design_revisions FOR EACH ROW EXECUTE FUNCTION badge_library_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_badge_templates', fn (Blueprint $table) => $table->dropColumn(['back', 'source_version_id']));
        Schema::connection('landlord')->dropIfExists('badge_design_revisions');
        Schema::connection('landlord')->dropIfExists('badge_library_versions');
        Schema::connection('landlord')->dropIfExists('badge_library_designs');
        DB::connection('landlord')->unprepared('DROP FUNCTION IF EXISTS badge_library_immutable()');
    }
};
