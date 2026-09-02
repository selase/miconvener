<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ResetPostgresSequences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:reset-postgres-sequences {--connection=landlord : The database connection to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset PostgreSQL sequences to the maximum ID in each table to avoid duplicate key errors.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = $this->option('connection');
        $connection = DB::connection($connectionName);

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error("Connection '{$connectionName}' is not a PostgreSQL connection (Driver: {$connection->getDriverName()}).");

            return 1;
        }

        $this->info("Resetting sequences for connection: {$connectionName}...");

        // Get all tables in the current schema
        $tables = $connection->select("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_type = 'BASE TABLE'
        ");

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            // Find the primary key column (usually 'id')
            $primaryKey = $this->getPrimaryKey($connectionName, $tableName);

            if (! $primaryKey) {
                continue;
            }

            // Check if the primary key has a sequence associated with it
            $sequence = $connection->selectOne("
                SELECT pg_get_serial_sequence('\"{$tableName}\"', '{$primaryKey}') as seq
            ");
            if (! $sequence) {
                continue;
            }
            if (! $sequence->seq) {
                continue;
            }

            $seqName = $sequence->seq;

            // Reset the sequence to the MAX(id)
            $this->comment("Restoring sequence for {$tableName}.{$primaryKey} ({$seqName})...");

            $connection->statement("
                SELECT setval('{$seqName}', COALESCE((SELECT MAX(\"{$primaryKey}\") FROM \"{$tableName}\"), 1) + 1, false)
            ");
        }

        $this->info('All PostgreSQL sequences have been reset.');

        return 0;
    }

    /**
     * Helper to find the primary key of a table.
     */
    private function getPrimaryKey(string $connection, string $table): ?string
    {
        $result = DB::connection($connection)->selectOne("
            SELECT a.attname
            FROM   pg_index i
            JOIN   pg_attribute a ON a.attrelid = i.indrelid
                                 AND a.attnum = ANY(i.indkey)
            WHERE  i.indrelid = '\"{$table}\"'::regclass
            AND    i.indisprimary;
        ");

        return $result?->attname;
    }
}
