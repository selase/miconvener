<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_wrong_number_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('phone');
            $table->string('phone_hash', 64);
            $table->text('note');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'phone_hash', 'status'], 'community_wrong_number_lookup');
        });
        Schema::connection('landlord')->create('community_contact_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedInteger('contact_version');
            $table->string('channel', 8);
            $table->text('address');
            $table->string('address_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('reason')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'contact_version', 'channel', 'address_hash'], 'community_contact_notice_unique');
        });
        Schema::connection('landlord')->table('dues_reminders', function (Blueprint $table): void {
            $table->unsignedInteger('contact_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('dues_reminders', fn (Blueprint $table) => $table->dropColumn('contact_version'));
        Schema::connection('landlord')->dropIfExists('community_contact_notices');
        Schema::connection('landlord')->dropIfExists('community_wrong_number_reports');
    }
};
