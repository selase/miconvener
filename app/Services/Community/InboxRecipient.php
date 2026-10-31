<?php

declare(strict_types=1);

namespace App\Services\Community;

final readonly class InboxRecipient
{
    public function __construct(public string $tenantId, public string $type, public string $id) {}
}
