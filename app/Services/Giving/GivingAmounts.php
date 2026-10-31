<?php

declare(strict_types=1);

namespace App\Services\Giving;

use Illuminate\Validation\ValidationException;

final class GivingAmounts
{
    public static function minor(string $decimal): int
    {
        if (! preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/', $decimal, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with at most two decimal places.']);
        }
        $minor = ((int) $matches[1] * 100) + (int) mb_str_pad($matches[2] ?? '', 2, '0');
        if ($minor <= 0 || $minor > 999999999999) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount within the collection limit.']);
        }

        return $minor;
    }
}
