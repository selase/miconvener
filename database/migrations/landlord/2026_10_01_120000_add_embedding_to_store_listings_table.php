<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = 'landlord';
        $driver = DB::connection($connection)->getDriverName();

        if ($driver === 'pgsql') {
            try {
                DB::connection($connection)->statement('CREATE EXTENSION IF NOT EXISTS vector;');
                DB::connection($connection)->statement('ALTER TABLE store_listings ADD COLUMN IF NOT EXISTS embedding vector(1536);');
                DB::connection($connection)->statement('CREATE INDEX IF NOT EXISTS store_listings_embedding_hnsw_idx ON store_listings USING hnsw (embedding vector_cosine_ops);');
            } catch (Throwable) {
                Schema::connection($connection)->table('store_listings', function (Blueprint $table) use ($connection): void {
                    if (! Schema::connection($connection)->hasColumn('store_listings', 'embedding')) {
                        $table->json('embedding')->nullable();
                    }
                });
            }
        } else {
            Schema::connection($connection)->table('store_listings', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        $connection = 'landlord';
        $driver = DB::connection($connection)->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection($connection)->statement('DROP INDEX IF EXISTS store_listings_embedding_hnsw_idx;');
            DB::connection($connection)->statement('ALTER TABLE store_listings DROP COLUMN IF EXISTS embedding;');
        } else {
            Schema::connection($connection)->table('store_listings', function (Blueprint $table): void {
                $table->dropColumn('embedding');
            });
        }
    }
};
