<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained('participant_groups')->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role_hint', 20)->default('participant');
            $table->boolean('can_record_hint')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'email']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_group_members');
    }
};
