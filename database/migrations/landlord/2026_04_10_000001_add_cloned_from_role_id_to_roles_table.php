<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('cloned_from_role_id')->nullable()->after('guard_name');
            $table->foreign('cloned_from_role_id')
                ->references('id')
                ->on('roles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign(['cloned_from_role_id']);
            $table->dropColumn('cloned_from_role_id');
        });
    }
};
