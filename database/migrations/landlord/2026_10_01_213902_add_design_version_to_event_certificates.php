<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_certificates', function (Blueprint $table): void {
            $table->foreignUuid('design_version_id')
                ->nullable()
                ->after('template_id')
                ->constrained('event_certificate_design_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_certificates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('design_version_id');
        });
    }
};
