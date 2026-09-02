<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TenantProvisioningException extends RuntimeException
{
    public function __construct(
        string $message = 'Tenant provisioning failed.',
        public readonly ?string $tenantId = null,
        public readonly ?string $step = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function databaseCreationFailed(string $tenantId, ?Throwable $previous = null): self
    {
        return new self(
            message: "Failed to create database for tenant {$tenantId}.",
            tenantId: $tenantId,
            step: 'database_creation',
            previous: $previous,
        );
    }

    public static function migrationFailed(string $tenantId, ?Throwable $previous = null): self
    {
        return new self(
            message: "Migration failed for tenant {$tenantId}.",
            tenantId: $tenantId,
            step: 'migration',
            previous: $previous,
        );
    }
}
