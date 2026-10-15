<?php

declare(strict_types=1);

namespace App\Services\Community;

final readonly class ImportResult
{
    /** @param array<int, array{line: int, status: string, errors: array<int, string>}> $rows */
    public function __construct(public int $created, public int $updated, public int $skipped, public int $errors, public array $rows) {}
}
