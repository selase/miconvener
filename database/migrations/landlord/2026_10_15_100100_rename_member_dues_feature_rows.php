<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The member_dues rollout switch becomes the community switch. Rows keep
 * their enabled flag and meta (admin_override), so no tenant changes state.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('landlord')->table('tenant_features')->where('feature_key', 'member_dues')->update(['feature_key' => 'community']);
    }

    public function down(): void
    {
        DB::connection('landlord')->table('tenant_features')->where('feature_key', 'community')->update(['feature_key' => 'member_dues']);
    }
};
