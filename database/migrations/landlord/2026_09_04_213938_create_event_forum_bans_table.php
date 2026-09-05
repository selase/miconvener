<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('event_forum_bans', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();

            $table->string('author_email');
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'author_email']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_forum_bans');
    }
};
