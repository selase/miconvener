<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->foreignUuid('abstract_id')->nullable()->after('type')->constrained('event_abstracts')->nullOnDelete();
        });

        Schema::connection('landlord')->table('event_session_speakers', function (Blueprint $table): void {
            $table->foreignUuid('abstract_id')->nullable()->after('role')->constrained('event_abstracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_session_speakers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('abstract_id');
        });

        Schema::connection('landlord')->table('event_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('abstract_id');
        });
    }
};
