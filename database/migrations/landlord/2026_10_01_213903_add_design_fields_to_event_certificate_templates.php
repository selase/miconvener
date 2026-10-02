<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_certificate_templates', function (Blueprint $table): void {
            $table->string('design_mode')->default('miconvener')->after('role');
            $table->string('orientation')->default('landscape')->after('design_mode');
            $table->string('page_size')->default('a4')->after('orientation');
            $table->json('layout')->nullable()->after('page_size');
            $table->unsignedInteger('design_version')->default(1)->after('layout');
            $table->string('signature_disk')->nullable()->after('signature_path');
            $table->string('background_disk')->nullable()->after('background_path');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_certificate_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'design_mode',
                'orientation',
                'page_size',
                'layout',
                'design_version',
                'signature_disk',
                'background_disk',
            ]);
        });
    }
};
