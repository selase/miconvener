<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\Tenant;

interface AudienceSource
{
    /** @param array<string, mixed> $selector
     * @return iterable<AudienceAddress>
     */
    public function addresses(Tenant $tenant, array $selector, string $channel): iterable;
}
