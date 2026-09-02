<?php

declare(strict_types=1);

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Feature::updateOrCreate(['slug' => 'llm_byok'], [
            'uuid' => (string) Str::uuid(),
            'name' => 'LLM Bring Your Own Key',
            'type' => 'boolean',
            'description' => 'Allow tenants to use their own LLM API keys.',
        ]);

        Feature::updateOrCreate(['slug' => 'llm_token_quota'], [
            'uuid' => (string) Str::uuid(),
            'name' => 'LLM Token Quota',
            'type' => 'limit',
            'description' => 'Maximum number of LLM tokens a tenant can consume.',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Feature::whereIn('slug', ['llm_byok', 'llm_token_quota'])->delete();
    }
};
