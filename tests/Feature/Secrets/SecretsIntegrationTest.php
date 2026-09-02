<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->secretsPath = storage_path('secrets.json');
    File::put($this->secretsPath, json_encode([
        'test_secret' => [
            'type' => 'db',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test',
            'username' => 'test',
            'password' => 'test',
        ],
    ]));
});

afterEach(function () {
    if (File::exists($this->secretsPath)) {
        File::delete($this->secretsPath);
    }
});

test('secrets provider is bound in container', function () {
    $provider = app(SecretsProvider::class);

    expect($provider)->toBeInstanceOf(SecretsProvider::class);

    $secret = $provider->getSecret('test_secret');
    expect($secret['database'])->toBe('test');
});
