<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->unsignedInteger('access_version')->default(1);
        });
        Schema::connection('landlord')->create('community_access_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->text('phone');
            $table->string('phone_hash', 64)->index();
            $table->string('claimant_name', 160);
            $table->string('role', 20);
            $table->string('capability_hash', 64);
            $table->foreignUuid('member_id')->nullable()->constrained('members')->cascadeOnDelete();
            $table->unsignedInteger('member_access_version')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 40)->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        Schema::connection('landlord')->create('community_access_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('members')->cascadeOnDelete();
            $table->text('phone');
            $table->string('phone_hash', 64)->index();
            $table->string('role', 20);
            $table->string('secret_hash');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('member_access_version');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 40);
            $table->timestampTz('approved_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'member_id']);
        });
        Schema::connection('landlord')->create('community_access_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('method', 40)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['community_access_audits', 'community_access_credentials', 'community_access_claims'] as $table) {
            Schema::connection('landlord')->dropIfExists($table);
        }
        Schema::connection('landlord')->table('members', fn (Blueprint $table) => $table->dropColumn('access_version'));
    }
};
