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
            $table->string('hero_image_path')->nullable()->after('cover_image_path');
            $table->text('plan_your_visit_content')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('events', function (Blueprint $table): void {
            $table->dropColumn(['hero_image_path', 'plan_your_visit_content']);
        });
    }
};
