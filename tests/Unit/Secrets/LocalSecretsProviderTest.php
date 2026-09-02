<?php

declare(strict_types=1);

use App\Contracts\Secrets\SecretsProvider;
use App\Services\Secrets\LocalSecretsProvider;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->secretsPath = storage_path('framework/testing/secrets.json');
    if (! File::isDirectory(dirname($this->secretsPath))) {
        File::makeDirectory(dirname($this->secretsPath), 0755, true);
    }
    File::put($this->secretsPath, json_encode([
        'db_tenant_1' => [
            'type' => 'db',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'tenant_1',
            'username' => 'user_1',
            'password' => 'pass_1',
        ],
        's3_tenant_1' => [
            'type' => 's3',
            'key' => 'aws_key',
            'secret' => 'aws_secret',
            'region' => 'us-east-1',
            'bucket' => 'tenant-1-bucket',
        ],
        'invalid_db' => [
            'type' => 'db',
            'host' => '127.0.0.1',
            // missing other keys
        ],
    ]));

    $this->provider = new LocalSecretsProvider($this->secretsPath);
});

afterEach(function () {
    if (File::exists($this->secretsPath)) {
        File::delete($this->secretsPath);
    }
});

test('it implements secrets provider interface', function () {
    expect($this->provider)->toBeInstanceOf(SecretsProvider::class);
});

test('it retrieves a valid db secret', function () {
    $secret = $this->provider->getSecret('db_tenant_1');

    expect($secret)->toBeArray()
        ->and($secret['host'])->toBe('127.0.0.1')
        ->and($secret['database'])->toBe('tenant_1');
});

test('it retrieves a valid s3 secret', function () {
    $secret = $this->provider->getSecret('s3_tenant_1');

    expect($secret)->toBeArray()
        ->and($secret['key'])->toBe('aws_key')
        ->and($secret['bucket'])->toBe('tenant-1-bucket');
});

test('it throws exception if secret ref not found', function () {
    $this->provider->getSecret('non_existent');
})->throws(RuntimeException::class, 'Secret reference [non_existent] not found');

test('it throws exception if db secret is missing required keys', function () {
    $this->provider->getSecret('invalid_db');
})->throws(InvalidArgumentException::class, 'Secret [invalid_db] is missing required keys for type [db]');
