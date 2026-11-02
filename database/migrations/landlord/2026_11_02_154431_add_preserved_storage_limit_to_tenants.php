<?php

declare(strict_types=1);

use App\Services\Tenancy\StorageAllowances;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('tenants', function (Blueprint $table): void {
            $table->bigInteger('storage_limit_snapshot')->nullable();
        });
        DB::connection('landlord')->statement('ALTER TABLE tenants ADD CONSTRAINT tenants_storage_snapshot_valid CHECK (storage_limit_snapshot IS NULL OR storage_limit_snapshot >= -1)');
        $policy = app(StorageAllowances::class);
        $policy->preserveExisting();
        $policy->installDefaults();
    }

    public function down(): void
    {
        throw new LogicException('Storage allowance snapshots preserve existing customer terms. Roll back application code without dropping these snapshots.');
    }
};
