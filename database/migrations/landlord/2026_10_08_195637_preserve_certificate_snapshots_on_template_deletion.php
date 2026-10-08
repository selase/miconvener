<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_certificate_design_versions', function (Blueprint $table): void {
            $table->dropForeign(['template_id']);
            $table->uuid('template_id')->nullable()->change();
            $table->foreign('template_id')->references('id')->on('event_certificate_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_certificate_design_versions', function (Blueprint $table): void {
            $table->dropForeign(['template_id']);
            $table->foreign('template_id')->references('id')->on('event_certificate_templates')->cascadeOnDelete();
        });
    }
};
