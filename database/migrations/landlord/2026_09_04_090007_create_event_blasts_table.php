<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_blasts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject');
            $table->text('body');
            $table->string('audience')->default('all'); // all, confirmed, checked_in
            $table->unsignedInteger('recipients_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_blasts');
    }
};
