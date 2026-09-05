<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_speakers', function (Blueprint $table): void {
            $table->string('portal_token', 64)->nullable()->unique()->after('speaker_id');
            $table->boolean('is_confirmed')->nullable()->after('role');
            $table->foreignUuid('slides_material_id')->nullable()->after('is_confirmed')->constrained('event_materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_speakers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('slides_material_id');
            $table->dropColumn(['portal_token', 'is_confirmed']);
        });
    }
};
