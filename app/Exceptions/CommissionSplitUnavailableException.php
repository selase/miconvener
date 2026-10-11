<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant on their own Paystack account cannot take a payment
 * because the platform commission would not reach the platform.
 */
final class CommissionSplitUnavailableException extends RuntimeException {}
