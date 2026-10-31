<?php

declare(strict_types=1);

namespace App\Services\Finance;

final readonly class ResolvedTerms
{
    public bool $isWaiver;

    public function __construct(
        public float $rate,
        public ?int $capAmount,
        public string $bearer,
        public string $source,
    ) {
        $this->isWaiver = $rate === 0.0;
    }
}
