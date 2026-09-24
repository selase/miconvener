<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_materials', function (Blueprint $table): void {
            $table->string('provenance', 20)->default('organizer')->after('mime_type');
        });

        DB::connection('landlord')->table('event_materials')
            ->whereIn('id', function ($query): void {
                $query->select('slides_material_id')
                    ->from('event_speakers')
                    ->whereNotNull('slides_material_id');
            })
            ->update(['provenance' => 'speaker']);
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_materials', function (Blueprint $table): void {
            $table->dropColumn('provenance');
        });
    }
};
