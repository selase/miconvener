<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('full_name');
            $table->string('first_name')->nullable()->after('title');
            $table->string('last_name')->nullable()->after('first_name');
            $table->json('form_answers')->nullable()->after('accessibility_needs');
        });

        $registrations = Schema::connection('landlord')->getConnection()
            ->table('event_registrations')
            ->select(['id', 'full_name'])
            ->get();

        foreach ($registrations as $reg) {
            $parts = explode(' ', mb_trim((string) $reg->full_name), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';

            Schema::connection('landlord')->getConnection()
                ->table('event_registrations')
                ->where('id', $reg->id)
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
        }
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_registrations', function (Blueprint $table): void {
            $table->dropColumn(['title', 'first_name', 'last_name', 'form_answers']);
        });
    }
};
