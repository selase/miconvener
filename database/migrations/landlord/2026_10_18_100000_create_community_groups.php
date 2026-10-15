<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('community_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
        Schema::connection('landlord')->create('community_group_members', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('community_groups')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['group_id', 'member_id']);
            $table->index(['tenant_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_group_members');
        Schema::connection('landlord')->dropIfExists('community_groups');
    }
};
