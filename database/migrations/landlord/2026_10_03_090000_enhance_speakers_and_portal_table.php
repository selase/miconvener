<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('speakers', function (Blueprint $table) {
            $table->string('website_url')->nullable()->after('photo_path');
            $table->string('linkedin_url')->nullable()->after('website_url');
            $table->string('twitter_url')->nullable()->after('linkedin_url');
        });

        Schema::connection('landlord')->table('event_speakers', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('is_confirmed');
            $table->timestamp('last_invited_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_speakers', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'last_invited_at']);
        });

        Schema::connection('landlord')->table('speakers', function (Blueprint $table) {
            $table->dropColumn(['website_url', 'linkedin_url', 'twitter_url']);
        });
    }
};
