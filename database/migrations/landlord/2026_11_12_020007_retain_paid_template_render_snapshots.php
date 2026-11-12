<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('landlord');
        $schema->table('badge_template_uses', function (Blueprint $table): void {
            $table->jsonb('render_snapshot')->default('{}');
        });
        foreach (['front' => 'render_snapshot', 'back' => "render_snapshot->'back'"] as $side => $root) {
            foreach (['background', 'logo'] as $asset) {
                $schema->getConnection()->statement("CREATE INDEX badge_use_{$side}_{$asset}_asset ON badge_template_uses (({$root}->>'{$asset}_disk'), ({$root}->>'{$asset}_path'))");
            }
        }
        $schema->getConnection()->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION badge_paid_family_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.price_pesewas IS DISTINCT FROM OLD.price_pesewas AND (OLD.price_pesewas > 0 OR EXISTS (SELECT 1 FROM badge_library_versions WHERE design_id = OLD.id)) THEN
        RAISE EXCEPTION 'A published design family retains its original price classification';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER badge_paid_family_guard BEFORE UPDATE ON badge_library_designs FOR EACH ROW EXECUTE FUNCTION badge_paid_family_guard();
CREATE OR REPLACE FUNCTION badge_provenance_delete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.source_type <> 'event' THEN
        RAISE EXCEPTION 'Saved paid design provenance is immutable';
    END IF;
    RETURN OLD;
END $$;
CREATE TRIGGER badge_provenance_delete_guard BEFORE DELETE ON badge_design_provenances FOR EACH ROW EXECUTE FUNCTION badge_provenance_delete_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retained paid design output evidence requires a forward migration.');
    }
};
