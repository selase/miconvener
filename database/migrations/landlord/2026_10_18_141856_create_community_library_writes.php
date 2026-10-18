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
        Schema::connection('landlord')->create('community_library_writes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->uuid('write_key');
            $t->string('fingerprint', 64);
            $t->uuid('item_id');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'actor_id', 'write_key'], 'library_write_once');
            $t->foreign(['tenant_id', 'item_id'], 'library_write_item')->references(['tenant_id', 'id'])->on('community_library_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('community_library_writes');
    }
};
