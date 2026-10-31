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
        Schema::table('membership_types', function (Blueprint $table): void {
            $table->date('dues_start_on')->nullable();
        });
        Schema::table('members', function (Blueprint $table): void {
            $table->date('dues_start_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('dues_start_on');
        });
        Schema::table('membership_types', function (Blueprint $table): void {
            $table->dropColumn('dues_start_on');
        });
    }
};
