<?php

declare(strict_types=1);

namespace App\Services\Community;

final readonly class ImportPreview
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $summary
     */
    public function __construct(public array $rows, public array $summary, public string $token) {}
}
