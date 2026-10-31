<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Contracts\DnsTxtResolver;

/**
 * Looks TXT records up with the server's own resolver. A failed lookup is an
 * empty answer, so a domain is never proved by an error.
 */
final class NativeDnsTxtResolver implements DnsTxtResolver
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_map(
            fn (array $record): string => (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))),
            $records,
        );
    }
}
