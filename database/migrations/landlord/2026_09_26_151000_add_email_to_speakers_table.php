<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('speakers', function (Blueprint $table): void {
            // Nullable because rows already exist without one; the form requires
            // it, so no new speaker can be created without an address. The column
            // becomes non-nullable once the backfill is done.
            $table->string('email')->nullable()->after('name');
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('speakers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'email']);
            $table->dropColumn('email');
        });
    }
};
