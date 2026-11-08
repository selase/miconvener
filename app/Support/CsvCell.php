<?php

declare(strict_types=1);

namespace App\Support;

final class CsvCell
{
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
