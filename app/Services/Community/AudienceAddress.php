<?php

declare(strict_types=1);

namespace App\Services\Community;

final readonly class AudienceAddress
{
    public function __construct(public string $address, public string $channel) {}
}
