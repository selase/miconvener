<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LlmTokenUsage;
use App\Models\Tenant;
use App\Services\Llm\LlmUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->service = app(LlmUsageService::class);
});

test('it records token usage correctly', function () {
    // Force load config for test if not loaded
    if (! Config::get('llm.models')) {
        $config = include base_path('config/llm.php');
        Config::set('llm', $config);
    }

    $usage = $this->service->record(
        $this->tenant->id,
        'openai',
        'gpt-3.5-turbo',
        100000,
        50000,
        ['endpoint' => '/api/chat'],
        null,
        null,
        '127.0.0.1'
    );

    expect($usage)->toBeInstanceOf(LlmTokenUsage::class);
    expect($usage->total_tokens)->toBe(150000);
    expect($usage->prompt_tokens)->toBe(100000);
    expect($usage->completion_tokens)->toBe(50000);
    expect($usage->provider)->toBe('openai');
    expect($usage->model)->toBe('gpt-3.5-turbo');
    expect($usage->cost_usd)->toBeGreaterThan(0);
});

test('it calculates cost correctly for known models', function () {
    // Mock config
    Config::set('llm.models.test-model', [
        'input_price_per_1k' => 0.01,
        'output_price_per_1k' => 0.02,
    ]);

    $cost = $this->service->calculateCost('test-model', 1000, 1000);

    // 1 * 0.01 + 1 * 0.02 = 0.03
    expect($cost)->toBe(0.03);
});

test('it handles unknown models gracefully', function () {
    $cost = $this->service->calculateCost('unknown-model', 1000, 1000);
    expect($cost)->toBe(0.0);
});
