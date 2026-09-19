<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Self-registration never set users.status, so the owner of every organization
 * that signed itself up carried a null status. Nothing gates login on it, so
 * those accounts behaved as active -- but code that asks for active users,
 * including the guard that refuses to remove an organization's final active
 * Org Superadmin, skipped right past them.
 *
 * Recording what was already true: these accounts are active.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('landlord')
            ->table('users')
            ->whereNull('status')
            ->update(['status' => User::STATUS_ACTIVE]);
    }

    public function down(): void
    {
        // Not reversible: which rows were null is not recoverable, and a null
        // status was never a meaningful state to restore.
    }
};
