<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->table('event_form_fields', function (Blueprint $table): void {
            $table->unsignedInteger('min_length')->nullable()->after('is_required');
            $table->unsignedInteger('max_length')->nullable()->after('min_length');
            $table->string('length_unit', 16)->default('characters')->after('max_length');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('event_form_fields', function (Blueprint $table): void {
            $table->dropColumn(['min_length', 'max_length', 'length_unit']);
        });
    }
};
