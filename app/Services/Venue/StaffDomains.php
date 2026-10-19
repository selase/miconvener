<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Models\Tenant;

/**
 * Email domains an organisation has proved it owns, which make addresses there
 * count as its staff. Only a verified domain grants anything, and only an exact
 * match: `mail.ugmc.example` and `evilugmc.example` are not `ugmc.example`.
 */
final class StaffDomains
{
    public function matches(Tenant $tenant, string $email): bool
    {
        $email = mb_strtolower(mb_trim($email));

        if (mb_substr_count($email, '@') !== 1) {
            return false;
        }

        $domain = mb_substr($email, (int) mb_strrpos($email, '@') + 1);

        foreach ($tenant->staff_email_domains ?? [] as $entry) {
            if (($entry['verified_at'] ?? null) !== null && mb_strtolower((string) $entry['domain']) === $domain) {
                return true;
            }
        }

        return false;
    }
}
