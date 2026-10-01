<?php

declare(strict_types=1);

namespace App\ValueObjects\Design;

final readonly class StoredArtwork
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public int $width,
        public int $height,
    ) {}
}
