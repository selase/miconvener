<?php

declare(strict_types=1);

namespace Tests\Support;

final class IsolatedPostgresDatabase
{
    public static function permits(string $database): bool
    {
        return preg_match('/^(?:testing|[a-z0-9_]+_testing)(?:_test_[1-9][0-9]*)?$/D', $database) === 1;
    }
}
