<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->unsignedInteger('hosts_version')->default(0);
        });
        Schema::connection('landlord')->create('event_hosts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('type', 20);
            $table->string('website_url', 2048)->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->index(['tenant_id', 'event_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('event_hosts');
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn('hosts_version');
        });
    }
};
