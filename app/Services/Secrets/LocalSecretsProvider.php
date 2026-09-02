<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Contracts\Secrets\SecretsProvider;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

final class LocalSecretsProvider implements SecretsProvider
{
    private readonly string $path;

    /** @var array<string, mixed>|null */
    private ?array $secrets = null;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('secrets.json');
    }

    /**
     * {@inheritDoc}
     */
    public function getSecret(string $ref): array
    {
        $this->loadSecrets();

        if (! isset($this->secrets[$ref])) {
            throw new RuntimeException("Secret reference [{$ref}] not found");
        }

        $secret = $this->secrets[$ref];
        $this->validateSecret($ref, $secret);

        return $secret;
    }

    private function loadSecrets(): void
    {
        if ($this->secrets !== null) {
            return;
        }

        if (! File::exists($this->path)) {
            $this->secrets = [];

            return;
        }

        $content = File::get($this->path);
        $this->secrets = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Failed to parse secrets file at [{$this->path}]: ".json_last_error_msg());
        }
    }

    /**
     * @param  array<string, mixed>  $secret
     */
    private function validateSecret(string $ref, array $secret): void
    {
        if (! isset($secret['type'])) {
            throw new InvalidArgumentException("Secret [{$ref}] is missing [type] field");
        }

        $requiredKeys = match ($secret['type']) {
            'db' => ['host', 'port', 'database', 'username', 'password'],
            's3' => ['key', 'secret', 'region', 'bucket'],
            'kms' => ['key_ref'],
            default => throw new InvalidArgumentException("Unknown secret type [{$secret['type']}] for secret [{$ref}]"),
        };

        foreach ($requiredKeys as $key) {
            if (! isset($secret[$key])) {
                throw new InvalidArgumentException("Secret [{$ref}] is missing required keys for type [{$secret['type']}]");
            }
        }
    }
}
