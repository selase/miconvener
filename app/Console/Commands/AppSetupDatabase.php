<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use PDO;

final class AppSetupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:setup-database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the initial PostgreSQL database if it does not exist and update .env credentials';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Interactive Prompts
        $database = $this->ask('Enter the PostgreSQL database name', env('DB_DATABASE', 'starterkit_landlord'));
        $user = $this->ask('Enter the PostgreSQL username', 'selase');
        $password = $this->ask('Enter the PostgreSQL password', 'root');
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '5432');

        $this->info("Setting up database for: $database");

        // 2. Update .env file to match these credentials
        $this->updateEnvFile($database, $user, $password);

        // 3. Ensure SQLite file exists for testing
        $sqlitePath = database_path('database.sqlite');
        if (! file_exists($sqlitePath)) {
            $this->info('Creating SQLite database file for testing...');
            touch($sqlitePath);
        }

        // 4. Create the PostgreSQL database
        try {
            $this->info("Connecting to PostgreSQL at $host:$port as $user...");

            $dsn = "pgsql:host=$host;port=$port;dbname=postgres";
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->query("SELECT 1 FROM pg_database WHERE datname = '{$database}'");
            if ($stmt->fetch() === false) {
                $this->info("Creating database: {$database}");
                $pdo->exec("CREATE DATABASE \"{$database}\"");
                $this->info('Database created successfully.');
            } else {
                $this->info("Database \"{$database}\" already exists.");
            }
        } catch (Exception $e) {
            $this->error('Failed to create database: '.$e->getMessage());
            $this->warn('Please ensure PostgreSQL is running and the user "'.$user.'" has permission to create databases.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Update the .env file with the specified credentials.
     */
    private function updateEnvFile(string $database, string $user, string $password): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);

        $changes = [
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
        ];

        $updated = false;
        foreach ($changes as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$value}", $content);
                $updated = true;
            }
            // If it doesn't exist at all, we don't necessarily want to append it
            // unless we're sure it should be there, but DB_USERNAME usually is.

        }

        if ($updated) {
            file_put_contents($envPath, $content);
            $this->info('Updated .env with database credentials.');
        }
    }
}
