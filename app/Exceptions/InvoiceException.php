<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvoiceException extends RuntimeException
{
    public static function invalidStatusTransition(string $from, string $to): self
    {
        return new self("Cannot transition invoice from '{$from}' to '{$to}'.");
    }

    public static function alreadyPaid(string $invoiceNumber): self
    {
        return new self("Invoice {$invoiceNumber} has already been paid.");
    }
}
