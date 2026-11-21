<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        $connection = DB::connection('landlord');
        $collision = $connection->table('users')->selectRaw('lower(btrim(email)) AS identity_email')->whereNotNull('email')->groupByRaw('lower(btrim(email))')->havingRaw('COUNT(*) > 1')->exists();
        if ($collision) {
            throw new RuntimeException('Canonical user email collisions require reviewed reconciliation; no accounts were merged.');
        }
        $connection->statement('CREATE UNIQUE INDEX users_email_identity_unique ON users (lower(btrim(email)))');
        $connection->table('users')->update(['email' => $connection->raw('lower(btrim(email))')]);
    }

    public function down(): void
    {
        DB::connection('landlord')->statement('DROP INDEX IF EXISTS users_email_identity_unique');
    }
};
