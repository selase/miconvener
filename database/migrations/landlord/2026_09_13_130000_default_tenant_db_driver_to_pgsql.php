<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MiConvener runs on PostgreSQL only. The column still defaulted to 'mysql', a
 * leftover from the multi-database starter kit — a tenant created without an
 * explicit driver would have been configured for a database that is not there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->string('db_driver')->default('pgsql')->change();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->string('db_driver')->default('mysql')->change();
        });
    }
};
