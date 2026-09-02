<?php

declare(strict_types=1);

namespace App\Contracts\Secrets;

interface SecretsProvider
{
    /**
     * Retrieve a secret by its reference.
     *
     * @return array<string, mixed>
     */
    public function getSecret(string $ref): array;
}
