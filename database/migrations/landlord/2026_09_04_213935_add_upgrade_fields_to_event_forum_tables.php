<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_forum_threads', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false)->after('author_email');
            $table->string('attachment_path')->nullable()->after('is_anonymous');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
        });

        Schema::connection('landlord')->table('event_forum_replies', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->after('tag');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_forum_replies', function (Blueprint $table): void {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_size']);
        });

        Schema::connection('landlord')->table('event_forum_threads', function (Blueprint $table): void {
            $table->dropColumn(['is_anonymous', 'attachment_path', 'attachment_name', 'attachment_size']);
        });
    }
};
