<?php

declare(strict_types=1);

namespace App\Services\ProjectManagement;

final readonly class PmSyncResult
{
    /**
     * @param  list<string>  $errorMessages
     */
    public function __construct(
        public int $pushed = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $errors = 0,
        public array $errorMessages = [],
    ) {}
}
