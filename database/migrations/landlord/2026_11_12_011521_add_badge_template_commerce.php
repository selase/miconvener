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
        $connection = $schema->getConnection();
        foreach (['store_commercial_agreements' => 'commerce_agreement_scope', 'store_orders' => 'commerce_order_values'] as $table => $constraint) {
            $definition = $connection->selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', [$constraint])->definition;
            if (! str_contains($definition, "'reservation'::character varying")) {
                throw new RuntimeException('Review the existing commerce type constraint before extending it.');
            }
            $definition = str_replace("'reservation'::character varying", "'reservation'::character varying, 'badge_template'::character varying", $definition);
            $connection->statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            $connection->statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} {$definition}");
        }
        $schema->table('events', fn (Blueprint $table) => $table->unique(['id', 'tenant_id'], 'badge_event_buyer_unique'));
        $schema->table('badge_library_versions', fn (Blueprint $table) => $table->unique(['id', 'design_id'], 'badge_version_family_unique'));
        $schema->create('badge_template_licences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('buyer_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('event_id');
            $table->foreign(['event_id', 'buyer_tenant_id'])->references(['id', 'tenant_id'])->on('events')->restrictOnDelete();
            $table->foreignUuid('family_id')->constrained('badge_library_designs')->restrictOnDelete();
            $table->string('state', 20)->default('pending');
            $table->uuid('current_purchase_id')->nullable();
            $table->timestampTz('first_used_at')->nullable();
            $table->timestamps();
            $table->unique(['buyer_tenant_id', 'event_id', 'family_id'], 'badge_event_family_licence_unique');
            $table->unique(['id', 'buyer_tenant_id', 'event_id', 'family_id'], 'badge_licence_owner_unique');
        });
        $schema->create('badge_template_purchases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('licence_id');
            $table->foreignUuid('order_id')->unique()->constrained('store_orders')->restrictOnDelete();
            $table->uuid('buyer_tenant_id');
            $table->uuid('event_id');
            $table->uuid('family_id');
            $table->uuid('version_id');
            $table->jsonb('terms_snapshot');
            $table->string('state', 20)->default('pending');
            $table->timestamps();
            $table->foreign(['licence_id', 'buyer_tenant_id', 'event_id', 'family_id'], 'badge_purchase_licence_fk')->references(['id', 'buyer_tenant_id', 'event_id', 'family_id'])->on('badge_template_licences')->restrictOnDelete();
            $table->foreign(['version_id', 'family_id'])->references(['id', 'design_id'])->on('badge_library_versions')->restrictOnDelete();
            $table->unique(['id', 'licence_id'], 'badge_purchase_slot_unique');
        });
        $schema->table('badge_template_licences', fn (Blueprint $table) => $table->foreign(['current_purchase_id', 'id'], 'badge_licence_current_purchase_fk')->references(['id', 'licence_id'])->on('badge_template_purchases')->restrictOnDelete());
        $schema->create('badge_template_licence_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('licence_id')->constrained('badge_template_licences')->restrictOnDelete();
            $table->uuid('purchase_id')->nullable();
            $table->foreign(['purchase_id', 'licence_id'])->references(['id', 'licence_id'])->on('badge_template_purchases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->uuid('operation_key');
            $table->jsonb('evidence');
            $table->timestamps();
            $table->unique(['licence_id', 'operation_key']);
        });
        $schema->create('badge_template_uses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('licence_id')->constrained('badge_template_licences')->restrictOnDelete();
            $table->uuid('purchase_id');
            $table->foreign(['purchase_id', 'licence_id'])->references(['id', 'licence_id'])->on('badge_template_purchases')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('version_id')->constrained('badge_library_versions')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->uuid('output_key');
            $table->string('snapshot_hash', 64);
            $table->timestamps();
            $table->unique(['licence_id', 'output_key']);
        });
        $schema->create('badge_design_provenances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type', 20);
            $table->uuid('source_id');
            $table->foreignUuid('family_id')->constrained('badge_library_designs')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'family_id'], 'badge_source_family_unique');
        });
        $schema->table('store_order_lines', function (Blueprint $table): void {
            $table->uuid('badge_purchase_id')->nullable();
            $table->foreign('badge_purchase_id')->references('id')->on('badge_template_purchases')->restrictOnDelete();
        });
        $connection->statement('ALTER TABLE store_order_lines ALTER COLUMN listing_id DROP NOT NULL');
        $line = $connection->selectOne("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = 'commerce_line_values'")->definition;
        $connection->statement('ALTER TABLE store_order_lines DROP CONSTRAINT commerce_line_values');
        $connection->statement("ALTER TABLE store_order_lines ADD CONSTRAINT commerce_line_values CHECK ((offering_type = 'badge_template' AND badge_purchase_id IS NOT NULL AND variant_id IS NULL AND booking_id IS NULL AND listing_id IS NULL AND quantity = 1 AND unit_price_pesewas = 5000 AND line_amount_pesewas = 5000) OR (badge_purchase_id IS NULL AND ".mb_substr($line, 7, -1).'))');
        $connection->statement("CREATE UNIQUE INDEX badge_one_pending_purchase ON badge_template_purchases (licence_id) WHERE state IN ('pending','review')");
        $connection->statement("CREATE UNIQUE INDEX badge_one_digital_line ON store_order_lines (order_id) WHERE offering_type = 'badge_template'");
        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION badge_purchase_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Digital purchase history is retained'; END IF;
    IF TG_OP = 'UPDATE' AND (to_jsonb(OLD) - ARRAY['state','updated_at']) IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['state','updated_at']) THEN
        RAISE EXCEPTION 'Digital purchase ownership and terms are immutable';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM store_orders WHERE id = NEW.order_id AND offering_type = 'badge_template') THEN
        RAISE EXCEPTION 'A digital purchase requires a digital order';
    END IF;
    RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION badge_licence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Digital licence history is retained'; END IF;
    IF (OLD.buyer_tenant_id, OLD.event_id, OLD.family_id) IS DISTINCT FROM (NEW.buyer_tenant_id, NEW.event_id, NEW.family_id) THEN
        RAISE EXCEPTION 'An event licence cannot be reassigned';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER badge_licence_guard BEFORE UPDATE OR DELETE ON badge_template_licences FOR EACH ROW EXECUTE FUNCTION badge_licence_guard();
CREATE TRIGGER badge_purchase_guard BEFORE INSERT OR UPDATE OR DELETE ON badge_template_purchases FOR EACH ROW EXECUTE FUNCTION badge_purchase_guard();
CREATE TRIGGER badge_licence_events_immutable BEFORE UPDATE OR DELETE ON badge_template_licence_events FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable();
CREATE TRIGGER badge_uses_immutable BEFORE UPDATE OR DELETE ON badge_template_uses FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable();
CREATE TRIGGER badge_provenance_immutable BEFORE UPDATE ON badge_design_provenances FOR EACH ROW EXECUTE FUNCTION commerce_snapshot_immutable();
ALTER TABLE badge_template_licences ADD CONSTRAINT badge_licence_state CHECK (state IN ('pending','active','suspended','refunded','revoked'));
ALTER TABLE badge_template_purchases ADD CONSTRAINT badge_purchase_state CHECK (state IN ('pending','review','paid','refunded','cancelled'));
ALTER TABLE badge_template_uses ADD CONSTRAINT badge_use_purpose CHECK (purpose IN ('clean_preview','artwork_download','badge_pdf','production_pack'));
ALTER TABLE badge_design_provenances ADD CONSTRAINT badge_provenance_source CHECK (source_type IN ('event','version','revision'));
CREATE OR REPLACE FUNCTION commerce_order_line_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM store_orders o WHERE o.id = NEW.order_id AND o.offering_type = NEW.offering_type) THEN
        RAISE EXCEPTION 'An order line must be of the same type as its order';
    END IF;
    IF NEW.offering_type = 'reservation' AND NOT EXISTS (SELECT 1 FROM venue_bookings b WHERE b.id = NEW.booking_id AND b.shop_id = NEW.shop_id AND b.store_listing_id = NEW.listing_id) THEN
        RAISE EXCEPTION 'A reservation line must name the booked venue of the same seller';
    END IF;
    IF NEW.offering_type = 'badge_template' AND NOT EXISTS (SELECT 1 FROM badge_template_purchases p WHERE p.id = NEW.badge_purchase_id AND p.order_id = NEW.order_id) THEN
        RAISE EXCEPTION 'A digital line must name its order purchase';
    END IF;
    RETURN NEW;
END; $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Digital financial history requires a reviewed forward migration. Disable paid checkout instead of deleting its history.');
    }
};
