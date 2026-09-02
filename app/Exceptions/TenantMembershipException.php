<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class TenantMembershipException extends HttpException
{
    public function __construct(string $message = 'You do not have access to this organization.', ?Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct(403, $message, $previous, $headers, $code);
    }
}
