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
        $schema = Schema::connection('landlord');
        $schema->table('tenants', fn (Blueprint $table) => $table->uuid('attendee_profile_generation')->nullable());
        $schema->table('events', function (Blueprint $table): void {
            $table->boolean('public_attendee_profiles')->default(false);
            $table->uuid('attendee_profile_generation')->nullable();
        });
        $schema->table('event_registrations', function (Blueprint $table): void {
            $table->uuid('attendee_profile_generation')->nullable();
            $table->unique(['tenant_id', 'event_id', 'id'], 'attendee_profile_registration_context');
        });
        $schema->create('event_attendee_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('event_id');
            $table->uuid('registration_id')->unique();
            $table->string('owner_hash', 64);
            $table->string('display_name', 80)->nullable();
            $table->string('state', 20)->default('private');
            $table->uuid('revision')->nullable();
            $table->uuid('feature_generation')->nullable();
            $table->uuid('tenant_generation')->nullable();
            $table->uuid('event_generation')->nullable();
            $table->uuid('registration_generation')->nullable();
            $table->uuid('image_id')->nullable();
            $table->uuid('operation_key')->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->boolean('adult_confirmed')->default(false);
            $table->string('consent_version', 32)->nullable();
            $table->timestampTz('consented_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['event_id', 'owner_hash']);
            $table->foreign(['tenant_id', 'event_id'])->references(['tenant_id', 'id'])->on('events')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'event_id', 'registration_id'])->references(['tenant_id', 'event_id', 'id'])->on('event_registrations')->cascadeOnDelete();
            $table->index(['event_id', 'state', 'expires_at']);
        });
        $schema->create('event_attendee_profile_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('event_id');
            $table->uuid('registration_id');
            $table->foreignUuid('profile_id')->nullable()->constrained('event_attendee_profiles')->nullOnDelete();
            $table->string('owner_hash', 64);
            $table->string('disk', 40);
            $table->string('path');
            $table->unsignedBigInteger('byte_size');
            $table->string('proof_hash', 64)->nullable();
            $table->uuid('registration_generation')->nullable();
            $table->timestampTz('stored_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'deleted_at']);
            $table->index(['retired_at', 'expires_at']);
        });
        $schema->create('event_attendee_profile_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('event_id');
            $table->uuid('profile_id');
            $table->string('subject_hash', 64);
            $table->uuid('revision')->nullable();
            $table->string('action', 24);
            $table->string('consent_version', 32)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 240)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('retain_until')->index();
        });
        DB::connection('landlord')->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION attendee_profile_tenant_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN NEW.attendee_profile_generation := gen_random_uuid();
 ELSE
  IF OLD.attendee_profile_generation IS NULL OR (OLD.status = 'active' AND NEW.status IS DISTINCT FROM OLD.status) THEN
   NEW.attendee_profile_generation := gen_random_uuid();
  ELSE NEW.attendee_profile_generation := OLD.attendee_profile_generation;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_tenant_generation BEFORE INSERT OR UPDATE ON tenants FOR EACH ROW EXECUTE FUNCTION attendee_profile_tenant_generation();
CREATE OR REPLACE FUNCTION attendee_profile_event_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  NEW.attendee_profile_generation := gen_random_uuid();
 ELSE
  IF OLD.attendee_profile_generation IS NULL OR
     (OLD.visibility = 'public' AND NEW.visibility IS DISTINCT FROM OLD.visibility) OR
     (OLD.status = 'published' AND NEW.status IS DISTINCT FROM OLD.status) OR
     OLD.starts_at IS DISTINCT FROM NEW.starts_at OR OLD.ends_at IS DISTINCT FROM NEW.ends_at OR
     OLD.is_recurring IS DISTINCT FROM NEW.is_recurring OR OLD.recurrence_until IS DISTINCT FROM NEW.recurrence_until OR
     (OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL) OR
     (OLD.public_attendee_profiles AND NOT NEW.public_attendee_profiles) THEN
   NEW.attendee_profile_generation := gen_random_uuid();
  ELSE NEW.attendee_profile_generation := OLD.attendee_profile_generation;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_event_generation BEFORE INSERT OR UPDATE ON events FOR EACH ROW EXECUTE FUNCTION attendee_profile_event_generation();
CREATE OR REPLACE FUNCTION attendee_profile_registration_generation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN NEW.attendee_profile_generation := gen_random_uuid();
 ELSE
  IF OLD.attendee_profile_generation IS NULL OR
     lower(trim(OLD.email)) IS DISTINCT FROM lower(trim(NEW.email)) OR
     OLD.qr_token IS DISTINCT FROM NEW.qr_token OR
     OLD.ticket_code IS DISTINCT FROM NEW.ticket_code OR
     (OLD.status IN ('confirmed','checked_in') AND NEW.status NOT IN ('confirmed','checked_in')) THEN
   NEW.attendee_profile_generation := gen_random_uuid();
  ELSE NEW.attendee_profile_generation := OLD.attendee_profile_generation;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_registration_generation BEFORE INSERT OR UPDATE ON event_registrations FOR EACH ROW EXECUTE FUNCTION attendee_profile_registration_generation();
CREATE OR REPLACE FUNCTION attendee_profile_feature_generation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE generation text;
BEGIN
 IF NEW.feature_key = 'public_attendee_profiles' THEN
  IF TG_OP = 'INSERT' THEN generation := gen_random_uuid()::text;
  ELSIF OLD.enabled AND NOT NEW.enabled THEN generation := gen_random_uuid()::text;
  ELSE generation := coalesce(OLD.meta::jsonb->>'profile_generation',gen_random_uuid()::text);
  END IF;
  NEW.meta := CASE WHEN jsonb_typeof(NEW.meta::jsonb) = 'object' THEN NEW.meta::jsonb ELSE '{}'::jsonb END || jsonb_build_object('profile_generation',generation);
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_feature_generation BEFORE INSERT OR UPDATE ON tenant_features FOR EACH ROW EXECUTE FUNCTION attendee_profile_feature_generation();
CREATE OR REPLACE FUNCTION attendee_profile_consent_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (to_jsonb(NEW)-'retain_until'-'actor_id') IS DISTINCT FROM (to_jsonb(OLD)-'retain_until'-'actor_id') OR
    NEW.retain_until > OLD.retain_until OR
    (NEW.actor_id IS DISTINCT FROM OLD.actor_id AND NEW.actor_id IS NOT NULL) THEN
  RAISE EXCEPTION 'Attendee consent evidence cannot be rewritten';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_consent_immutable BEFORE UPDATE ON event_attendee_profile_consents FOR EACH ROW EXECUTE FUNCTION attendee_profile_consent_immutable();
CREATE OR REPLACE FUNCTION attendee_profile_image_context() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NOT EXISTS (SELECT 1 FROM event_registrations r JOIN events e ON e.id=r.event_id AND e.tenant_id=r.tenant_id
    WHERE r.id=NEW.registration_id AND r.event_id=NEW.event_id AND r.tenant_id=NEW.tenant_id) THEN
   RAISE EXCEPTION 'Invalid attendee image registration context';
  END IF;
 ELSE
  IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.event_id IS DISTINCT FROM OLD.event_id OR
     NEW.registration_id IS DISTINCT FROM OLD.registration_id OR NEW.owner_hash IS DISTINCT FROM OLD.owner_hash OR
     NEW.proof_hash IS DISTINCT FROM OLD.proof_hash OR NEW.registration_generation IS DISTINCT FROM OLD.registration_generation OR
     NEW.disk IS DISTINCT FROM OLD.disk OR NEW.path IS DISTINCT FROM OLD.path OR NEW.byte_size IS DISTINCT FROM OLD.byte_size THEN
   RAISE EXCEPTION 'Attendee image ownership and storage cannot be rewritten';
  END IF;
 END IF;
 IF NEW.profile_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_attendee_profiles p
   WHERE p.id=NEW.profile_id AND p.tenant_id=NEW.tenant_id AND p.event_id=NEW.event_id AND p.registration_id=NEW.registration_id AND p.owner_hash=NEW.owner_hash) THEN
  RAISE EXCEPTION 'Invalid attendee image profile context';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER attendee_profile_image_context BEFORE INSERT OR UPDATE ON event_attendee_profile_images FOR EACH ROW EXECUTE FUNCTION attendee_profile_image_context();
CREATE OR REPLACE FUNCTION attendee_profile_consent_retention() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.retain_until > clock_timestamp() THEN RAISE EXCEPTION 'Attendee consent evidence has not reached its retention cutoff'; END IF;
 RETURN OLD;
END $$;
CREATE TRIGGER attendee_profile_consent_retention BEFORE DELETE ON event_attendee_profile_consents FOR EACH ROW EXECUTE FUNCTION attendee_profile_consent_retention();
ALTER TABLE event_attendee_profiles ADD CONSTRAINT attendee_profile_state CHECK (state IN ('private','published','withdrawn','expired','hidden'));
ALTER TABLE event_attendee_profiles ADD CONSTRAINT attendee_profile_publication_complete CHECK (state <> 'published' OR
 (display_name IS NOT NULL AND length(trim(display_name)) > 0 AND adult_confirmed AND
 revision IS NOT NULL AND feature_generation IS NOT NULL AND tenant_generation IS NOT NULL AND event_generation IS NOT NULL AND registration_generation IS NOT NULL AND
 consent_version IS NOT NULL AND consented_at IS NOT NULL AND expires_at IS NOT NULL AND expires_at > consented_at AND expires_at <= consented_at + interval '90 days'));
ALTER TABLE event_attendee_profile_images ADD CONSTRAINT attendee_profile_image_size CHECK (byte_size >= 0);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Attendee consent and cleanup evidence must be retained. Roll back application code without dropping these records.');
    }
};
