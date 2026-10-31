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
        Schema::connection('landlord')->create('community_join_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->text('address');
            $table->string('address_hash', 64);
            $table->uuid('request_key');
            $table->string('submitted_name');
            $table->foreignUuid('membership_type_id')->constrained('membership_types');
            $table->foreignUuid('member_id')->nullable()->constrained('members');
            $table->unsignedInteger('member_access_version')->nullable();
            $table->string('status', 30);
            $table->string('capability_hash', 64);
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('credential_issued_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'address_hash', 'request_key'], 'community_join_request_identity');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_join_requests');
    }
};
