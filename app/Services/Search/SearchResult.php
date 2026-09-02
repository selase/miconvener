<?php

declare(strict_types=1);

namespace App\Services\Search;

final class SearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public string $type, // e.g., 'User', 'Invoice', 'Page'
        public ?string $description = null,
        public ?string $icon = null, // SVG or icon class
    ) {}
}
