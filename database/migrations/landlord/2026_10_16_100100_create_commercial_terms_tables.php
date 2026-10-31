<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('commercial_terms_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 20);
            $table->decimal('rate', 5, 2);
            $table->unsignedBigInteger('cap_amount')->nullable();
            $table->text('reason');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'stream']);
        });
        Schema::connection('landlord')->create('commercial_terms_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 20);
            $table->decimal('old_rate', 5, 2)->nullable();
            $table->decimal('new_rate', 5, 2)->nullable();
            $table->unsignedBigInteger('old_cap')->nullable();
            $table->unsignedBigInteger('new_cap')->nullable();
            $table->text('reason');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('landlord')->create('dues_payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->json('charge_ids');
            $table->string('currency', 3);
            $table->unsignedBigInteger('expected_amount');
            $table->decimal('rate', 5, 2);
            $table->unsignedBigInteger('cap_amount')->nullable();
            $table->string('bearer', 20);
            $table->json('commissions');
            $table->string('terms_source', 20);
            $table->string('status', 16)->default('open');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('dues_payment_attempts');
        Schema::connection('landlord')->dropIfExists('commercial_terms_changes');
        Schema::connection('landlord')->dropIfExists('commercial_terms_overrides');
    }
};
