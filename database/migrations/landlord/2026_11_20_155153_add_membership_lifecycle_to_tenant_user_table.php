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
        Schema::connection('landlord')->table('tenant_user', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
        });
    }

    public function down(): void
    {
        if (Illuminate\Support\Facades\DB::connection('landlord')->table('tenant_user')->where('status', '!=', 'active')->exists()) {
            throw new RuntimeException('Membership lifecycle has been used. Roll forward instead of restoring revoked access.');
        }
        Schema::connection('landlord')->table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn(['status', 'accepted_at', 'suspended_at', 'revoked_at', 'changed_by']);
        });
    }
};
